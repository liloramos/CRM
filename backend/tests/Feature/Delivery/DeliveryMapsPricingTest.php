<?php

namespace Tests\Feature\Delivery;

use App\Contracts\Delivery\DeliveryGeocodingProviderInterface;
use App\Contracts\Delivery\DeliveryRouteProviderInterface;
use App\Data\Delivery\DeliveryCoordinates;
use App\Data\Delivery\DeliveryRoute;
use App\Data\WhatsApp\IncomingWhatsAppMessage;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliverySetting;
use App\Models\Order;
use App\Services\Delivery\DeliveryPricingService;
use App\Services\Delivery\DeliveryRoutingService;
use App\Services\Delivery\WhatsAppDeliveryLocationCapture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryMapsPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_km_uses_meters_and_nearest_cent_without_calling_a_real_provider(): void
    {
        $setting = DeliverySetting::query()->create([
            'company_id' => $this->company()->id,
            'calculation_mode' => DeliveryPricingService::MODE_PER_KM,
            'price_per_km_cents' => 300,
        ]);

        $result = app(DeliveryPricingService::class)->calculate($setting, 3400);

        $this->assertSame(1020, $result['delivery_fee_cents']);
        $this->assertSame(0, $result['surcharge_cents']);
        $this->assertSame(3400, $result['snapshot']['distance_meters']);
    }

    public function test_distance_bands_have_unambiguous_inclusive_upper_limits(): void
    {
        $service = app(DeliveryPricingService::class);
        $setting = DeliverySetting::query()->create([
            'company_id' => $this->company()->id,
            'calculation_mode' => DeliveryPricingService::MODE_DISTANCE_BANDS,
            'price_per_km_cents' => 0,
            'provider_options' => ['distance_bands' => [
                ['up_to_meters' => 2000, 'fee_cents' => 500],
                ['up_to_meters' => 4000, 'fee_cents' => 800],
            ]],
        ]);

        $this->assertSame(500, $service->calculate($setting, 2000)['delivery_fee_cents']);
        $this->assertSame(800, $service->calculate($setting, 2001)['delivery_fee_cents']);
    }

    public function test_routing_snapshots_the_calculation_and_updates_the_order_total_once(): void
    {
        $company = $this->company();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente de teste']);
        $address = CustomerAddress::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'street' => 'Rua de teste',
            'latitude' => -23.55,
            'longitude' => -46.63,
        ]);
        $order = Order::query()->create([
            'company_id' => $company->id,
            'payer_customer_id' => $customer->id,
            'delivery_address_id' => $address->id,
            'order_date' => now()->toDateString(),
            'daily_sequence' => 1,
            'code' => 'ENT-TESTE-1',
            'fulfillment_type' => Order::FULFILLMENT_DELIVERY,
            'subtotal_cents' => 1500,
            'total_cents' => 1500,
            'amount_due_cents' => 1500,
        ]);
        DeliverySetting::query()->create([
            'company_id' => $company->id,
            'calculation_mode' => DeliveryPricingService::MODE_PER_KM,
            'price_per_km_cents' => 300,
            'provider_options' => ['origin' => ['latitude' => -23.54, 'longitude' => -46.62]],
        ]);
        $this->app->instance(DeliveryRouteProviderInterface::class, new class implements DeliveryRouteProviderInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function calculateRoute(DeliveryCoordinates $origin, DeliveryCoordinates $destination): DeliveryRoute
            {
                return new DeliveryRoute(3400, 720, 'fake', 'route-test', 'encoded');
            }
        });
        $this->app->instance(DeliveryGeocodingProviderInterface::class, new class implements DeliveryGeocodingProviderInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function geocode(string $address): never
            {
                throw new \LogicException('Not used.');
            }
        });

        $quote = app(DeliveryRoutingService::class)->recalculate($order);

        $order->refresh();
        $this->assertSame(1020, $quote->delivery_fee_cents);
        $this->assertSame(1020, $order->total_cents);
        $this->assertSame(1020, $order->amount_due_cents);
        $this->assertSame(3400, $quote->maps_metadata['distance_meters']);
        $this->assertSame(720, $quote->maps_metadata['duration_seconds']);
        $this->assertSame(-23.54, $quote->maps_metadata['origin']['latitude']);
    }

    public function test_whatsapp_location_is_saved_even_when_route_calculation_is_not_configured(): void
    {
        $company = $this->company();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente de localização']);
        $order = Order::query()->create([
            'company_id' => $company->id,
            'payer_customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'daily_sequence' => 1,
            'code' => 'ENT-LOC-1',
            'fulfillment_type' => Order::FULFILLMENT_DELIVERY,
        ]);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'active_order_id' => $order->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => 'manual',
            'whatsapp_identifier' => '5511999999999',
        ]);

        app(WhatsAppDeliveryLocationCapture::class)->capture($conversation->load('activeOrder'), new IncomingWhatsAppMessage(
            provider: 'fake', providerAccountId: null, providerMessageId: 'location-test', from: '5511999999999', to: null,
            senderName: null, messageType: 'location', text: null, sentAt: null,
            rawPayload: ['location' => ['latitude' => -23.55, 'longitude' => -46.63]],
        ));

        $this->assertSame('-23.5500000', CustomerAddress::query()->firstOrFail()->latitude);
        $this->assertSame('-46.6300000', CustomerAddress::query()->firstOrFail()->longitude);
        $this->assertSame('whatsapp_location', CustomerAddress::query()->firstOrFail()->metadata['location_source']);
        $this->assertNotNull($order->refresh()->delivery_address_id);
    }

    private function company(): Company
    {
        return Company::query()->create([
            'name' => 'Restaurante de teste '.uniqid(),
            'slug' => 'restaurante-teste-'.uniqid(),
        ]);
    }
}
