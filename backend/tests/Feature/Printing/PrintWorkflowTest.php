<?php

namespace Tests\Feature\Printing;

use App\Models\Company;
use App\Models\Customer;
use App\Models\MenuComponent;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\PrintJobEvent;
use App\Models\Product;
use App\Models\ProductGroupComponent;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Printing\PrintWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PrintingSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
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

    public function test_ticket_preview_route_supports_autoprint_and_thermal_print_styles(): void
    {
        [$company, $order] = $this->createOperationalOrder();
        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ATENDENTE);

        $response = $this->actingAs($user)
            ->get("/orders/{$order->id}/ticket/preview?autoprint=1")
            ->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('COMANDA DE PEDIDO', $html);
        $this->assertStringContainsString('size: 80mm auto;', $html);
        $this->assertStringContainsString('width: 76mm;', $html);
        $this->assertStringContainsString('break-inside: avoid;', $html);
        $this->assertStringContainsString("get('autoprint') === '1'", $html);
        $this->assertStringContainsString('window.print()', $html);
        $this->assertDatabaseHas('print_jobs', [
            'order_id' => $order->id,
            'requested_by_user_id' => $user->id,
        ]);
    }

    public function test_ticket_uses_order_customer_snapshot_and_omits_empty_item_recipient(): void
    {
        $this->seed([CompanySeeder::class, PrintingSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company, [
            'customer_name_snapshot' => 'Cliente Avulso da Rua',
            'customer_phone_snapshot' => '(62) 91111-2222',
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
        ]);

        $orders->addItem($order, $product, [
            'item_notes' => 'Sem observacao especial de pessoa.',
            'beneficiary_name' => null,
        ]);

        $html = app(PrintWorkflowService::class)->generateTicket($order->refresh())->html_content;

        $this->assertStringContainsString('Pagador: Cliente Avulso da Rua', $html);
        $this->assertStringContainsString('Telefone: (62) 91111-2222', $html);
        $this->assertStringNotContainsString('Para: Nao informado', $html);
        $this->assertStringNotContainsString('Para:', $html);
    }

    public function test_ticket_displays_special_item_recipient_only_when_informed(): void
    {
        $this->seed([CompanySeeder::class, PrintingSeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente Principal']);
        $product = Product::query()->where('slug', 'n5-casa')->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company, [
            'payer_customer_id' => $customer->id,
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
        ]);

        $orders->addItem($order, $product, [
            'beneficiary_name' => 'Larissa',
        ]);

        $html = app(PrintWorkflowService::class)->generateTicket($order->refresh())->html_content;

        $this->assertStringContainsString('Pagador: Cliente Principal', $html);
        $this->assertStringContainsString('Para: Larissa', $html);
    }

    public function test_ticket_prints_n8_and_n9_beef_modes_with_only_selected_options(): void
    {
        $this->seed([PrintingSeeder::class, SolRestaurantStructuredMenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company, [
            'customer_name_snapshot' => 'Cliente do Bife',
            'order_date' => CarbonImmutable::create(2026, 7, 6),
        ]);
        $porco = $this->menuComponentId($company, 'porco');
        $frango = $this->menuComponentId($company, 'frango-ao-molho');
        $n8 = Product::query()->where('company_id', $company->id)->where('slug', 'n8-tradicional')->firstOrFail();
        $n9 = Product::query()->where('company_id', $company->id)->where('slug', 'n9-tradicional')->firstOrFail();

        $this->actingAs($user)->postJson("/api/app/orders/{$order->id}/items", [
            'product_id' => $n8->id,
            'quantity' => 1,
            'meat_mode' => 'beef_only',
            'structured_options' => [],
        ])->assertOk();

        $this->actingAs($user)->postJson("/api/app/orders/{$order->id}/items", [
            'product_id' => $n8->id,
            'quantity' => 1,
            'meat_mode' => 'traditional',
            'traditional_meat_component_ids' => [$porco, $frango],
            'additions' => [
                ['code' => 'extra_beef', 'quantity' => 1],
            ],
            'structured_options' => [],
        ])->assertOk();

        $this->actingAs($user)->postJson("/api/app/orders/{$order->id}/items", [
            'product_id' => $n9->id,
            'quantity' => 1,
            'meat_mode' => 'beef_only',
            'structured_options' => [],
        ])->assertOk();

        $this->actingAs($user)->postJson("/api/app/orders/{$order->id}/items", [
            'product_id' => $n9->id,
            'quantity' => 1,
            'meat_mode' => 'traditional',
            'traditional_meat_component_ids' => [$porco, $frango],
            'additions' => [
                ['code' => 'extra_beef', 'quantity' => 1],
            ],
            'structured_options' => [],
        ])->assertOk();

        $html = app(PrintWorkflowService::class)->generateTicket($order->refresh())->html_content;

        $this->assertStringContainsString('1x N8 Tradicional', $html);
        $this->assertStringContainsString('1x Somente bife', $html);
        $this->assertStringContainsString('R$ 20,00', $html);
        $this->assertStringContainsString('1x Porco', $html);
        $this->assertStringContainsString('1x Frango ao molho', $html);
        $this->assertStringContainsString('1x Bife adicional', $html);
        $this->assertStringContainsString('R$ 23,00', $html);
        $this->assertStringContainsString('1x N9 Tradicional', $html);
        $this->assertStringContainsString('R$ 22,00', $html);
        $this->assertStringContainsString('R$ 25,00', $html);
        $this->assertStringNotContainsString('Almondega', $html);
        $this->assertStringNotContainsString('Para: Nao informado', $html);
    }

    public function test_ticket_prints_removed_default_components_without_selected_noise(): void
    {
        $this->seed([PrintingSeeder::class, SolRestaurantStructuredMenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company, [
            'customer_name_snapshot' => 'Cliente da Composição',
            'order_date' => CarbonImmutable::create(2026, 7, 6),
        ]);
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'n5-casa')->firstOrFail();
        $feijao = $this->menuComponentId($company, 'feijao');

        $this->actingAs($user)->postJson("/api/app/orders/{$order->id}/items", [
            'product_id' => $product->id,
            'quantity' => 1,
            'removed_component_ids' => [$feijao],
            'structured_options' => $this->componentChoiceRows($product, [
                'salada_casa' => ['beterraba'],
                'carne' => ['porco'],
            ]),
        ])->assertOk();

        $html = app(PrintWorkflowService::class)->generateTicket($order->refresh())->html_content;

        $this->assertStringContainsString('1x N5 Casa', $html);
        $this->assertStringContainsString('1x Arroz', $html);
        $this->assertStringContainsString('1x Beterraba', $html);
        $this->assertStringContainsString('1x Porco', $html);
        $this->assertStringContainsString('Sem Feijão', $html);
        $this->assertStringNotContainsString('1x Feijão', $html);
        $this->assertStringNotContainsString('Repolho com tomate', $html);
    }

    public function test_ticket_preview_route_keeps_company_isolation(): void
    {
        [, $order] = $this->createOperationalOrder();
        $this->seed(RoleAndPermissionSeeder::class);

        $otherCompany = Company::query()->create([
            'name' => 'Outro Restaurante',
            'slug' => 'outro-restaurante',
        ]);
        $user = User::factory()->create(['company_id' => $otherCompany->id]);
        $user->assignRole(Role::ATENDENTE);

        $this->actingAs($user)
            ->get("/orders/{$order->id}/ticket/preview?autoprint=1")
            ->assertNotFound();

        $this->assertDatabaseMissing('print_jobs', [
            'order_id' => $order->id,
            'requested_by_user_id' => $user->id,
        ]);
    }

    public function test_preparation_requires_printed_ticket_or_manual_authorization(): void
    {
        [, $order] = $this->createOperationalOrder();
        $orders = app(OrderWorkflowService::class);
        $printing = app(PrintWorkflowService::class);
        $job = $printing->generateTicket($order);

        try {
            $orders->transitionTo($order->refresh(), Order::STATUS_IN_PREPARATION, reason: 'prepare_without_ticket');
            $this->fail('Preparation should require printed ticket.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('ticket is printed', $exception->getMessage());
        }

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

    private function menuComponentId(Company $company, string $slug): int
    {
        return (int) MenuComponent::query()
            ->where('company_id', $company->id)
            ->where('slug', $slug)
            ->firstOrFail()
            ->id;
    }

    /**
     * @param  array<string, list<string>>  $choices
     * @return list<array{component_link_id: int}>
     */
    private function componentChoiceRows(Product $product, array $choices): array
    {
        $rows = [];

        foreach ($choices as $groupCode => $componentSlugs) {
            $group = ProductOptionGroup::query()
                ->where('product_id', $product->id)
                ->where('code', $groupCode)
                ->firstOrFail();

            foreach ($componentSlugs as $componentSlug) {
                $component = MenuComponent::query()
                    ->where('company_id', $product->company_id)
                    ->where('slug', $componentSlug)
                    ->firstOrFail();

                $link = ProductGroupComponent::query()
                    ->where('product_option_group_id', $group->id)
                    ->where('menu_component_id', $component->id)
                    ->firstOrFail();

                $rows[] = ['component_link_id' => (int) $link->id];
            }
        }

        return $rows;
    }
}
