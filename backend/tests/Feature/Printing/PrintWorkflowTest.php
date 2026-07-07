<?php

namespace Tests\Feature\Printing;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\PrintJobEvent;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Printing\PrintWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PrintingSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_preview_contains_order_items_notes_payment_and_pickup_details(): void
    {
        [$company, $order] = $this->createOperationalOrder();
        $user = User::factory()->create(['company_id' => $company->id]);
        $printing = app(PrintWorkflowService::class);

        $job = $printing->generateTicket($order, $user);
        $order->refresh();

        $this->assertSame(PrintJob::STATUS_PREVIEWED, $job->status);
        $this->assertSame(Order::PRINT_STATUS_PREVIEWED, $order->print_status);
        $this->assertSame(Order::STATUS_READY_TO_PRINT, $order->status);
        $this->assertNotNull($order->ticket_generated_at);
        $this->assertStringContainsString('COMANDA DE PEDIDO', $job->html_content);
        $this->assertStringContainsString('Cliente Pagador Sanitizado', $job->html_content);
        $this->assertStringContainsString('Pessoa Autorizada', $job->html_content);
        $this->assertStringContainsString('Pouco arroz, sem fritura.', $job->html_content);
        $this->assertStringContainsString('Credito usado', $job->html_content);
        $this->assertStringContainsString('Falta', $job->html_content);
        $this->assertDatabaseHas('print_job_events', [
            'order_id' => $order->id,
            'print_job_id' => $job->id,
            'event_type' => PrintJobEvent::EVENT_TICKET_GENERATED,
        ]);
    }

    public function test_preparation_requires_printed_ticket_or_manual_authorization(): void
    {
        [, $order] = $this->createOperationalOrder();
        $orders = app(OrderWorkflowService::class);
        $printing = app(PrintWorkflowService::class);

        try {
            $orders->transitionTo($order, Order::STATUS_IN_PREPARATION, reason: 'prepare_without_ticket');
            $this->fail('Preparation should require printed ticket.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('ticket is printed', $exception->getMessage());
        }

        $job = $printing->generateTicket($order->refresh());
        $printing->markPrinted($job);
        $preparedOrder = $orders->transitionTo($order->refresh(), Order::STATUS_IN_PREPARATION, reason: 'prep_after_ticket');

        $this->assertSame(Order::STATUS_IN_PREPARATION, $preparedOrder->status);
        $this->assertSame(Order::PRINT_STATUS_PRINTED, $preparedOrder->print_status);
    }

    public function test_print_failure_and_reprint_are_audited(): void
    {
        [, $order] = $this->createOperationalOrder();
        $printing = app(PrintWorkflowService::class);

        $job = $printing->generateTicket($order);
        $failedJob = $printing->failPrint($job, message: 'Driver do navegador retornou erro operacional.');
        $reprint = $printing->requestReprint($failedJob, reason: 'Reimpressao apos falha operacional.');

        $this->assertSame(PrintJob::STATUS_REPRINT_REQUESTED, $failedJob->refresh()->status);
        $this->assertSame(PrintJob::STATUS_PREVIEWED, $reprint->status);
        $this->assertTrue($reprint->is_reprint);
        $this->assertSame(2, $reprint->copy_number);
        $this->assertSame($failedJob->id, $reprint->parent_print_job_id);
        $this->assertSame(Order::PRINT_STATUS_PREVIEWED, $order->refresh()->print_status);
        $this->assertDatabaseHas('print_job_events', [
            'order_id' => $order->id,
            'print_job_id' => $failedJob->id,
            'event_type' => PrintJobEvent::EVENT_PRINT_FAILED,
        ]);
        $this->assertDatabaseHas('print_job_events', [
            'order_id' => $order->id,
            'print_job_id' => $failedJob->id,
            'event_type' => PrintJobEvent::EVENT_REPRINT_REQUESTED,
        ]);
    }

    public function test_manual_print_confirmation_allows_preparation_without_browser_job(): void
    {
        [$company, $order] = $this->createOperationalOrder();
        $user = User::factory()->create(['company_id' => $company->id]);
        $printing = app(PrintWorkflowService::class);
        $orders = app(OrderWorkflowService::class);

        $manualOrder = $printing->markManualPrinted($order, $user, 'Comanda confirmada manualmente no atendimento.');
        $preparedOrder = $orders->transitionTo($manualOrder, Order::STATUS_IN_PREPARATION, $user, 'prep_after_manual_print');

        $this->assertSame(Order::STATUS_IN_PREPARATION, $preparedOrder->status);
        $this->assertSame(Order::PRINT_STATUS_MANUAL_CONFIRMED, $preparedOrder->print_status);
        $this->assertDatabaseHas('print_job_events', [
            'order_id' => $order->id,
            'event_type' => PrintJobEvent::EVENT_MANUAL_CONFIRMED,
        ]);
    }

    /**
     * @return array{0: Company, 1: Order}
     */
    private function createOperationalOrder(): array
    {
        $this->seed([CompanySeeder::class, PrintingSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Pagador Sanitizado',
        ]);

        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company, [
            'payer_customer_id' => $customer->id,
            'order_date' => CarbonImmutable::create(2026, 7, 6),
            'origin_channel' => Order::CHANNEL_COUNTER,
            'entry_mode' => Order::CHANNEL_MANUAL,
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
            'pickup_person_name' => 'Pessoa Autorizada',
            'pickup_authorized_by' => 'Cliente Pagador Sanitizado',
            'general_notes' => 'Pedido conferido manualmente para preparo.',
            'kitchen_notes' => 'Separar item com observacao.',
        ]);

        $product = Product::query()->where('slug', 'n8-casa')->firstOrFail();
        $option = ProductOption::query()->where('slug', 'ovo-frito')->firstOrFail();

        $orders->addItem($order, $product, [
            'item_notes' => 'Pouco arroz, sem fritura.',
            'beneficiary_name' => 'Pessoa Beneficiaria',
            'beneficiary_notes' => 'Item separado para retirada.',
            'preferences' => ['mais_salada'],
            'restrictions' => ['sem_fritura'],
            'selected_components' => ['salada' => 'vinagrete'],
            'options' => [
                ['product_option_id' => $option->id],
            ],
        ]);

        return [$company, $order->refresh()];
    }
}
