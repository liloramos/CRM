<?php

namespace Tests\Feature\Delivery;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliverySetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WhatsAppMessageDelivery;
use App\Services\Delivery\DeliveryWorkflowService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\WhatsApp\WhatsAppService;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\WhatsAppSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DeliveryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_distance_quote_applies_delivery_fee_to_order_total(): void
    {
        [$company, $customer, $order, $address] = $this->createDeliveryOrder();
        $user = User::factory()->create(['company_id' => $company->id]);
        $delivery = app(DeliveryWorkflowService::class);

        $quote = $delivery->quoteDelivery($order, [
            'customer_address_id' => $address->id,
            'distance_km' => 3.5,
            'quoted_by_user_id' => $user->id,
            'delivery_notes' => 'Entregar na recepcao do endereco sanitizado.',
        ]);

        $order->refresh();

        $this->assertSame(700, $quote->base_fee_cents);
        $this->assertSame('10.00', $quote->surcharge_percent);
        $this->assertSame(70, $quote->surcharge_cents);
        $this->assertSame(770, $quote->delivery_fee_cents);
        $this->assertSame(Order::FULFILLMENT_DELIVERY, $order->fulfillment_type);
        $this->assertSame(Order::DELIVERY_STATUS_QUOTED, $order->delivery_status);
        $this->assertSame(Order::FULFILLMENT_STATUS_DELIVERY_QUOTED, $order->fulfillment_status);
        $this->assertSame(770, $order->delivery_fee_cents);
        $this->assertSame(2070, $order->total_cents);
        $this->assertSame(2070, $order->amount_due_cents);
        $this->assertSame('Rua Exemplo', $order->delivery_address_snapshot['street']);
        $this->assertSame(DeliverySetting::MAPS_PROVIDER_NONE, $quote->maps_provider);
    }

    public function test_pickup_by_third_party_clears_delivery_fee_and_keeps_pickup_authorization(): void
    {
        [$company, $customer, $order, $address] = $this->createDeliveryOrder();
        $delivery = app(DeliveryWorkflowService::class);

        $delivery->quoteDelivery($order, [
            'customer_address_id' => $address->id,
            'distance_km' => 2,
        ]);

        $pickupOrder = $delivery->configurePickup($order->refresh(), [
            'pickup_person_name' => 'Pessoa Autorizada',
            'pickup_authorized_by' => 'Cliente Pagador Sanitizado',
            'pickup_notes' => 'Retirada por terceiro confirmada no atendimento.',
        ]);

        $this->assertSame(Order::FULFILLMENT_PICKUP, $pickupOrder->fulfillment_type);
        $this->assertSame(Order::FULFILLMENT_STATUS_PICKUP_PENDING, $pickupOrder->fulfillment_status);
        $this->assertSame(Order::PICKUP_STATUS_PENDING, $pickupOrder->pickup_status);
        $this->assertSame('Pessoa Autorizada', $pickupOrder->pickup_person_name);
        $this->assertSame('Cliente Pagador Sanitizado', $pickupOrder->pickup_authorized_by);
        $this->assertNull($pickupOrder->delivery_status);
        $this->assertNull($pickupOrder->delivery_address_id);
        $this->assertSame(0, $pickupOrder->delivery_fee_cents);
        $this->assertSame(1300, $pickupOrder->total_cents);
        $this->assertSame($customer->id, $pickupOrder->payer_customer_id);
    }

    public function test_delivery_status_updates_order_lifecycle_without_external_maps_call(): void
    {
        [$company, $customer, $order, $address] = $this->createDeliveryOrder();
        $user = User::factory()->create(['company_id' => $company->id]);
        $delivery = app(DeliveryWorkflowService::class);

        $quote = $delivery->quoteDelivery($order, [
            'customer_address_id' => $address->id,
            'distance_km' => 1.25,
            'maps_provider' => 'future_maps_provider',
            'external_route_id' => 'future-route-placeholder',
            'maps_metadata' => ['external_api_called' => false],
        ]);

        $orders = app(OrderWorkflowService::class);
        $orders->transitionTo($order->refresh(), Order::STATUS_READY_TO_PRINT, $user, 'ticket_ready');
        $order->forceFill([
            'print_required' => false,
            'print_status' => Order::PRINT_STATUS_WAIVED,
        ])->save();
        $orders->transitionTo($order->refresh(), Order::STATUS_IN_PREPARATION, $user, 'preparation_started');
        $delivery->markReady($order->refresh(), $user);

        $outForDelivery = $delivery->updateDeliveryStatus(
            $order->refresh(),
            Order::DELIVERY_STATUS_OUT_FOR_DELIVERY,
            $user,
            'Entrega saiu para rota manual.',
        );

        $this->assertSame(Order::STATUS_OUT_FOR_DELIVERY, $outForDelivery->status);
        $this->assertSame(Order::DELIVERY_STATUS_OUT_FOR_DELIVERY, $outForDelivery->delivery_status);
        $this->assertSame(Order::FULFILLMENT_STATUS_DELIVERY_OUT, $outForDelivery->fulfillment_status);
        $this->assertSame('future_maps_provider', $quote->maps_provider);
        $this->assertSame('future-route-placeholder', $quote->external_route_id);
        $this->assertFalse($quote->maps_metadata['external_api_called']);

        $finished = $delivery->updateDeliveryStatus(
            $outForDelivery,
            Order::DELIVERY_STATUS_DELIVERED,
            $user,
            'Entrega concluida manualmente.',
        );

        $this->assertSame(Order::STATUS_FINISHED, $finished->status);
        $this->assertSame(Order::DELIVERY_STATUS_DELIVERED, $finished->delivery_status);
        $this->assertSame(Order::FULFILLMENT_STATUS_DELIVERED, $finished->fulfillment_status);
        $this->assertSame($customer->id, $finished->payer_customer_id);
    }

    public function test_dispatch_and_delivery_are_idempotent_and_customer_notices_are_optional(): void
    {
        [$company, $customer, $order, $address] = $this->createDeliveryOrder();
        $this->seed(WhatsAppSeeder::class);
        Config::set('chatbotcrm.whatsapp.provider', 'fake');
        $customer->forceFill(['phone' => '5511999999999'])->save();
        $order->forceFill(['customer_phone_snapshot' => '5511999999999'])->save();
        $user = User::factory()->create(['company_id' => $company->id]);
        $delivery = app(DeliveryWorkflowService::class);
        $orders = app(OrderWorkflowService::class);

        $delivery->quoteDelivery($order, ['customer_address_id' => $address->id, 'distance_km' => 1]);
        $orders->transitionTo($order->refresh(), Order::STATUS_READY_TO_PRINT, $user, 'test_ready_to_print');
        $order->forceFill([
            'print_required' => false,
            'print_status' => Order::PRINT_STATUS_WAIVED,
        ])->save();
        $orders->transitionTo($order->refresh(), Order::STATUS_IN_PREPARATION, $user, 'test_preparation');
        $ready = $delivery->markReady($order->refresh(), $user);
        $out = $delivery->startDelivery($ready, $user);

        $this->assertSame(Order::STATUS_OUT_FOR_DELIVERY, $out->status);
        $this->assertSame(0, WhatsAppMessageDelivery::query()->count());

        $first = $delivery->notifyCustomer($out, 'out_for_delivery', app(WhatsAppService::class), $user);
        $second = $delivery->notifyCustomer($out, 'out_for_delivery', app(WhatsAppService::class), $user);

        $this->assertTrue($first['sent']);
        $this->assertTrue($second['sent']);
        $this->assertSame(1, WhatsAppMessageDelivery::query()->count());
        $this->assertSame($user->id, $out->statusHistories()->latest()->firstOrFail()->user_id);

        $finished = $delivery->markDelivered($out, $user);
        $this->assertSame(Order::STATUS_FINISHED, $finished->status);
        $this->assertSame(1, WhatsAppMessageDelivery::query()->count());

        $thankYou = $delivery->notifyCustomer($finished, 'delivered', app(WhatsAppService::class), $user);
        $this->assertTrue($thankYou['sent']);
        $this->assertSame(2, WhatsAppMessageDelivery::query()->count());
    }

    /**
     * @return array{0: Company, 1: Customer, 2: Order, 3: CustomerAddress}
     */
    private function createDeliveryOrder(): array
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Pagador Sanitizado',
        ]);
        $address = CustomerAddress::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'label' => 'Endereco sanitizado',
            'recipient_name' => 'Pessoa Recebedora',
            'street' => 'Rua Exemplo',
            'number' => '100',
            'neighborhood' => 'Bairro Teste',
            'city' => 'Cidade Teste',
            'state' => 'GO',
            'reference' => 'Referencia segura sem dados reais.',
        ]);
        DeliverySetting::query()->create([
            'company_id' => $company->id,
            'calculation_mode' => DeliverySetting::CALCULATION_MANUAL_DISTANCE,
            'price_per_km_cents' => 200,
            'surcharge_percent' => 10,
        ]);

        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company, [
            'payer_customer_id' => $customer->id,
            'order_date' => CarbonImmutable::create(2026, 7, 6),
            'fulfillment_type' => Order::FULFILLMENT_DELIVERY,
        ]);

        $product = Product::query()->where('slug', 'n8-casa')->firstOrFail();
        $orders->addItem($order, $product);

        return [$company, $customer, $order->refresh(), $address];
    }
}
