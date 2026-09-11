<?php

namespace Tests\Feature\Orders;

use App\Models\Company;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PrintJob;
use App\Models\PrintJobEvent;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Delivery\DeliveryWorkflowService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Payments\PaymentWorkflowService;
use App\Services\Printing\PrintWorkflowService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PrintingSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_must_be_confirmed_by_a_human_before_preparation_and_reprint_does_not_regress_the_order(): void
    {
        [$company, $user, $order, $payment] = $this->paidOrder(Order::FULFILLMENT_DELIVERY);
        $orderCount = Order::query()->count();
        $paymentCount = Payment::query()->count();

        $preview = $this->actingAs($user)
            ->postJson("/api/app/orders/{$order->id}/ticket-preview")
            ->assertOk()
            ->assertJsonPath('data.order.backendStatus', Order::STATUS_READY_TO_PRINT)
            ->assertJsonPath('data.order.printStatus', 'aguardando')
            ->json('data.preview');

        $this->assertSame(PrintJob::STATUS_PREVIEWED, $preview['status']);
        $this->assertSame(Order::STATUS_READY_TO_PRINT, $order->refresh()->status);
        $this->assertNull($order->printed_at);
        $previewJobCount = PrintJob::query()->where('order_id', $order->id)->count();

        $started = $this->postJson("/api/app/orders/{$order->id}/print/start")
            ->assertOk()
            ->assertJsonPath('data.order.backendStatus', Order::STATUS_READY_TO_PRINT)
            ->assertJsonPath('data.order.latestPrintJobStatus', PrintJob::STATUS_PRINTING)
            ->assertJsonPath('data.order.printConfirmationPending', true)
            ->json('data.preview');

        $this->assertSame(PrintJob::STATUS_PRINTING, $started['status']);
        $this->assertSame(Order::STATUS_READY_TO_PRINT, $order->refresh()->status);
        $this->assertNull($order->printed_at);
        $this->assertSame($previewJobCount, PrintJob::query()->where('order_id', $order->id)->count());

        $this->postJson("/api/app/orders/{$order->id}/print/start")
            ->assertOk()
            ->assertJsonPath('data.preview.id', $started['id'])
            ->assertJsonPath('data.preview.status', PrintJob::STATUS_PRINTING);
        $this->assertSame($previewJobCount, PrintJob::query()->where('order_id', $order->id)->count());

        $this->postJson("/api/app/orders/{$order->id}/print/confirm")
            ->assertOk()
            ->assertJsonPath('data.backendStatus', Order::STATUS_IN_PREPARATION)
            ->assertJsonPath('data.status', 'em_preparo')
            ->assertJsonPath('data.printStatus', 'impresso')
            ->assertJsonPath('data.printConfirmationPending', false);

        $confirmedJobId = (int) $order->refresh()->latest_print_job_id;
        $printEventCount = PrintJobEvent::query()
            ->where('print_job_id', $confirmedJobId)
            ->where('event_type', PrintJobEvent::EVENT_PRINTED)
            ->count();
        $preparationHistoryCount = $order->statusHistories()
            ->where('reason', 'preparation_started_after_print_confirmation')
            ->count();

        $this->postJson("/api/app/orders/{$order->id}/print/confirm")
            ->assertOk()
            ->assertJsonPath('data.backendStatus', Order::STATUS_IN_PREPARATION);

        $this->assertSame($printEventCount, PrintJobEvent::query()
            ->where('print_job_id', $confirmedJobId)
            ->where('event_type', PrintJobEvent::EVENT_PRINTED)
            ->count());
        $this->assertSame($preparationHistoryCount, $order->statusHistories()
            ->where('reason', 'preparation_started_after_print_confirmation')
            ->count());

        $this->postJson("/api/app/orders/{$order->id}/print/start")
            ->assertOk()
            ->assertJsonPath('data.order.backendStatus', Order::STATUS_IN_PREPARATION)
            ->assertJsonPath('data.order.printStatus', 'impresso')
            ->assertJsonPath('data.order.printConfirmationPending', true);

        $reprint = $order->refresh()->latestPrintJob()->firstOrFail();
        $this->assertTrue($reprint->is_reprint);
        $this->assertSame($confirmedJobId, $reprint->parent_print_job_id);

        $this->postJson("/api/app/orders/{$order->id}/print/confirm")
            ->assertOk()
            ->assertJsonPath('data.backendStatus', Order::STATUS_IN_PREPARATION)
            ->assertJsonPath('data.printStatus', 'impresso');

        $this->assertSame($orderCount, Order::query()->count());
        $this->assertSame($paymentCount, Payment::query()->count());
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->refresh()->status);
        $this->assertSame((int) $order->total_cents, (int) $payment->confirmed_amount_cents);
        $this->assertSame($user->id, $order->refresh()->seller_user_id);
    }

    public function test_delivery_requires_mounting_confirmation_and_projects_ready_before_dispatch(): void
    {
        [, $user, $order, $payment] = $this->paidOrder(Order::FULFILLMENT_DELIVERY);
        $printing = app(PrintWorkflowService::class);
        $delivery = app(DeliveryWorkflowService::class);
        $job = $printing->generateTicket($order->refresh(), $user);
        $printing->markPrinting($job, $user);
        $printing->markPrinted($job, $user);

        $this->assertSame(Order::STATUS_IN_PREPARATION, $order->refresh()->status);

        try {
            $delivery->startDelivery($order->refresh(), $user);
            $this->fail('Delivery must remain blocked until mounting is confirmed.');
        } catch (DomainException $exception) {
            $this->assertSame('O pedido precisa estar pronto antes de sair para entrega.', $exception->getMessage());
        }

        $ready = $delivery->markReady($order->refresh(), $user);
        $readyAgain = $delivery->markReady($ready, $user);

        $this->assertSame(Order::STATUS_READY_FOR_PICKUP, $readyAgain->status);
        $this->assertSame(1, $order->statusHistories()->where('reason', 'preparation_ready')->count());

        $this->actingAs($user)
            ->getJson('/api/app/deliveries')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $order->id)
            ->assertJsonPath('data.0.status', 'ready')
            ->assertJsonPath('data.0.order_status', Order::STATUS_READY_FOR_PICKUP);

        $this->postJson("/api/app/orders/{$order->id}/fulfillment/start-delivery")
            ->assertOk()
            ->assertJsonPath('data.backendStatus', Order::STATUS_OUT_FOR_DELIVERY);
        $this->postJson("/api/app/orders/{$order->id}/fulfillment/start-delivery")
            ->assertOk()
            ->assertJsonPath('data.backendStatus', Order::STATUS_OUT_FOR_DELIVERY);

        $this->assertSame(1, $order->statusHistories()->where('reason', 'delivery_out')->count());
        $this->assertSame(Order::DELIVERY_STATUS_OUT_FOR_DELIVERY, $order->refresh()->delivery_status);
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->refresh()->status);

        $this->postJson("/api/app/orders/{$order->id}/fulfillment/delivered")
            ->assertOk()
            ->assertJsonPath('data.backendStatus', Order::STATUS_FINISHED);
        $this->postJson("/api/app/orders/{$order->id}/fulfillment/delivered")
            ->assertOk()
            ->assertJsonPath('data.backendStatus', Order::STATUS_FINISHED);

        $this->assertSame(Order::DELIVERY_STATUS_DELIVERED, $order->refresh()->delivery_status);
        $this->assertSame(1, $order->statusHistories()->where('reason', 'delivery_finished')->count());
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->refresh()->status);
        $this->actingAs($user)
            ->getJson('/api/app/operational-snapshot')
            ->assertOk()
            ->assertJsonPath('data.orders.0.backendStatus', Order::STATUS_FINISHED)
            ->assertJsonPath('data.orders.0.status', 'finalizado');
    }

    public function test_pickup_uses_the_same_ready_state_without_delivery_dispatch(): void
    {
        [, $user, $order, $payment] = $this->paidOrder(Order::FULFILLMENT_PICKUP);
        $printing = app(PrintWorkflowService::class);
        $delivery = app(DeliveryWorkflowService::class);
        $job = $printing->generateTicket($order->refresh(), $user);
        $printing->markPrinting($job, $user);
        $printing->markPrinted($job, $user);

        $ready = $delivery->markReady($order->refresh(), $user);

        $this->assertSame(Order::STATUS_READY_FOR_PICKUP, $ready->status);
        $this->assertSame(Order::PICKUP_STATUS_READY, $ready->pickup_status);
        $this->assertSame(Order::FULFILLMENT_STATUS_READY_FOR_PICKUP, $ready->fulfillment_status);

        try {
            $delivery->startDelivery($ready, $user);
            $this->fail('Pickup must not enter the delivery dispatch flow.');
        } catch (DomainException $exception) {
            $this->assertSame('Somente pedidos de entrega podem sair para entrega.', $exception->getMessage());
        }

        $finished = $delivery->markPickedUp($ready, $user);

        $this->assertSame(Order::STATUS_FINISHED, $finished->status);
        $this->assertSame(Order::PICKUP_STATUS_PICKED_UP, $finished->pickup_status);
        $this->assertNull($finished->delivery_status);
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->refresh()->status);
        $this->assertDatabaseMissing('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => Order::STATUS_OUT_FOR_DELIVERY,
        ]);
        $this->actingAs($user)
            ->getJson('/api/app/operational-snapshot')
            ->assertOk()
            ->assertJsonPath('data.orders.0.backendStatus', Order::STATUS_FINISHED)
            ->assertJsonPath('data.orders.0.status', 'finalizado');
    }

    public function test_print_confirmation_endpoints_require_printing_authority(): void
    {
        [$company, , $order] = $this->paidOrder(Order::FULFILLMENT_PICKUP);
        $userWithoutRole = User::factory()->create(['company_id' => $company->id]);
        $originalStatus = $order->status;

        $this->actingAs($userWithoutRole)
            ->postJson("/api/app/orders/{$order->id}/print/start")
            ->assertForbidden();

        $this->actingAs($userWithoutRole)
            ->postJson("/api/app/orders/{$order->id}/print/confirm")
            ->assertForbidden();

        $this->assertSame($originalStatus, $order->refresh()->status);
        $this->assertSame(0, PrintJob::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
    }

    /**
     * @return array{0: Company, 1: User, 2: Order, 3: Payment}
     */
    private function paidOrder(string $fulfillmentType): array
    {
        $this->seed([
            CompanySeeder::class,
            RoleAndPermissionSeeder::class,
            PrintingSeeder::class,
            MenuSeeder::class,
        ]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create([
            'company_id' => $company->id,
            'can_be_seller' => true,
        ]);
        $user->assignRole(Role::ATENDENTE);
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company, [
            'customer_name_snapshot' => 'Cliente do lifecycle',
            'customer_phone_snapshot' => '5511999999999',
            'seller_user_id' => $user->id,
            'fulfillment_type' => $fulfillmentType,
        ]);
        $orders->addItem($order, $product);

        if ($fulfillmentType === Order::FULFILLMENT_DELIVERY) {
            $order->forceFill([
                'delivery_status' => Order::DELIVERY_STATUS_QUOTED,
                'fulfillment_status' => Order::FULFILLMENT_STATUS_DELIVERY_QUOTED,
                'delivery_address_snapshot' => [
                    'street' => 'Rua do Sol',
                    'number' => '10',
                    'neighborhood' => 'Centro',
                    'city' => 'Goiânia',
                    'state' => 'GO',
                    'latitude' => -16.68,
                    'longitude' => -49.25,
                ],
            ])->save();
        }

        $payment = app(PaymentWorkflowService::class)->confirmOrderPayment($order->refresh(), $user, [
            'method' => Payment::METHOD_PIX,
            'amount_cents' => (int) $order->refresh()->total_cents,
        ]);

        return [$company, $user, $order->refresh(), $payment];
    }
}
