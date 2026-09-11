<?php

namespace Tests\Feature\Orders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CounterSaleDraftWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_weight_drafts_open_without_weight_payment_conversation_or_delivery(): void
    {
        [$company, $user] = $this->companyAndOperator();

        foreach (['comida-por-kg-comum' => 5500, 'comida-por-kg-somente-carne' => 7000] as $slug => $rate) {
            $draft = $this->openDraft($user, $this->catalogProduct($company, $slug));
            $order = Order::query()->with(['items', 'payments', 'deliveryQuotes'])->findOrFail($draft['id']);
            $item = $order->items->sole();

            $this->assertSame(Order::STATUS_DRAFT, $order->status);
            $this->assertSame(Order::CHANNEL_COUNTER, $order->origin_channel);
            $this->assertSame(Order::CHANNEL_COUNTER, $order->entry_mode);
            $this->assertSame(Order::FULFILLMENT_COUNTER, $order->fulfillment_type);
            $this->assertSame(Order::FULFILLMENT_STATUS_PICKUP_PENDING, $order->fulfillment_status);
            $this->assertMatchesRegularExpression('/^\d{8}-\d{4}$/', $order->code);
            $this->assertNull($item->weight_grams);
            $this->assertSame($rate, $item->price_per_kg_cents);
            $this->assertSame(0, $item->unit_price_cents);
            $this->assertSame(0, $order->payments->count());
            $this->assertSame(0, $order->amount_paid_cents);
            $this->assertNull($order->conversation_id);
            $this->assertNull($order->delivery_address_id);
            $this->assertCount(0, $order->deliveryQuotes);
        }
    }

    public function test_self_service_draft_preserves_known_components_beef_and_notes_without_payment(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $selfService = $this->catalogProduct($company, 'self-service');

        $draft = $this->actingAs($user)
            ->postJson('/api/app/counter-sales/drafts', [
                'product_id' => $selfService->id,
                'selected_components' => ['arroz', 'feijão'],
                'additions' => [['code' => 'extra_beef', 'quantity' => 1]],
                'notes' => 'Comanda física 12.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.backendStatus', Order::STATUS_DRAFT)
            ->assertJsonPath('data.paymentStatus', 'pendente')
            ->json('data');

        $order = Order::query()->with(['items.options', 'payments'])->findOrFail($draft['id']);
        $item = $order->items->sole();

        $this->assertSame(1900, $item->unit_price_cents);
        $this->assertSame(['arroz', 'feijão'], $item->selected_components);
        $this->assertSame(700, $item->options->sole()->total_price_cents);
        $this->assertSame('Comanda física 12.', $order->general_notes);
        $this->assertCount(0, $order->payments);
    }

    public function test_standard_weight_draft_finalizes_the_same_order_with_canonical_total_and_payment(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $product = $this->catalogProduct($company, 'comida-por-kg-comum');
        $draft = $this->openDraft($user, $product);

        $finalized = $this->finalizeDraft($user, $draft['id'], [
            'weight_grams' => 540,
            'payment_method' => Payment::METHOD_PIX,
        ]);
        $order = Order::query()->with(['items.options', 'payments'])->findOrFail($finalized['id']);
        $item = $order->items->sole();

        $this->assertSame($draft['id'], $finalized['id']);
        $this->assertSame(Order::STATUS_FINISHED, $order->status);
        $this->assertSame(540, $item->weight_grams);
        $this->assertSame(5500, $item->price_per_kg_cents);
        $this->assertSame(2970, $item->unit_price_cents);
        $this->assertSame(2970, $order->total_cents);
        $this->assertSame(2970, $order->payments->sole()->confirmed_amount_cents);
        $this->assertSame(Payment::STATUS_CONFIRMED, $order->payments->sole()->status);
    }

    public function test_standard_weight_draft_accepts_beef_at_finalize_as_a_separate_canonical_option(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $draft = $this->openDraft($user, $this->catalogProduct($company, 'comida-por-kg-comum'));

        $this->finalizeDraft($user, $draft['id'], [
            'weight_grams' => 540,
            'additions' => [['code' => 'extra_beef', 'quantity' => 1]],
            'payment_method' => Payment::METHOD_CASH,
        ]);

        $order = Order::query()->with(['items.options', 'payments'])->findOrFail($draft['id']);
        $item = $order->items->sole();
        $beef = $item->options->sole();

        $this->assertSame(2970, $item->unit_price_cents);
        $this->assertSame(700, $item->options_total_cents);
        $this->assertSame(3670, $order->total_cents);
        $this->assertSame('bife_adicional', $beef->group_code);
        $this->assertSame(700, $beef->total_price_cents);
        $this->assertSame(3670, $order->payments->sole()->confirmed_amount_cents);
    }

    public function test_meat_only_and_self_service_drafts_finalize_with_their_canonical_prices(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $meat = $this->openDraft($user, $this->catalogProduct($company, 'comida-por-kg-somente-carne'));
        $selfService = $this->openDraft($user, $this->catalogProduct($company, 'self-service'));
        $selfServiceWithBeef = $this->actingAs($user)
            ->postJson('/api/app/counter-sales/drafts', [
                'product_id' => $this->catalogProduct($company, 'self-service')->id,
                'additions' => [['code' => 'extra_beef', 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('data');

        $this->finalizeDraft($user, $meat['id'], [
            'weight_grams' => 450,
            'payment_method' => Payment::METHOD_PIX,
        ]);
        $this->finalizeDraft($user, $selfService['id'], ['payment_method' => Payment::METHOD_PIX]);
        $this->finalizeDraft($user, $selfServiceWithBeef['id'], ['payment_method' => Payment::METHOD_PIX]);

        $this->assertSame(3150, Order::query()->findOrFail($meat['id'])->total_cents);
        $this->assertSame(1900, Order::query()->findOrFail($selfService['id'])->total_cents);
        $this->assertSame(2600, Order::query()->findOrFail($selfServiceWithBeef['id'])->total_cents);
        $this->assertSame(3, Payment::query()->where('company_id', $company->id)->count());
    }

    public function test_weight_draft_cannot_finalize_without_a_valid_integer_weight(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $draft = $this->openDraft($user, $this->catalogProduct($company, 'comida-por-kg-comum'));

        foreach ([[], ['weight_grams' => 0], ['weight_grams' => -1], ['weight_grams' => 10001], ['weight_grams' => 540.5]] as $attributes) {
            $this->actingAs($user)
                ->postJson("/api/app/counter-sales/{$draft['id']}/finalize", [
                    ...$attributes,
                    'payment_method' => Payment::METHOD_PIX,
                ])
                ->assertUnprocessable();
        }

        $order = Order::query()->with('payments')->findOrFail($draft['id']);
        $this->assertSame(Order::STATUS_DRAFT, $order->status);
        $this->assertNull($order->items()->sole()->weight_grams);
        $this->assertCount(0, $order->payments);
    }

    public function test_finalize_is_idempotent_and_does_not_duplicate_items_payments_or_revenue(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $draft = $this->openDraft($user, $this->catalogProduct($company, 'comida-por-kg-comum'));
        $payload = ['weight_grams' => 540, 'payment_method' => Payment::METHOD_PIX];

        $first = $this->finalizeDraft($user, $draft['id'], $payload);
        $second = $this->finalizeDraft($user, $draft['id'], $payload);
        $order = Order::query()->with(['items', 'payments'])->findOrFail($draft['id']);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(Order::STATUS_FINISHED, $order->status);
        $this->assertCount(1, $order->items);
        $this->assertCount(1, $order->payments);
        $this->assertSame(2970, $order->amount_paid_cents);
        $this->assertSame(2970, Payment::query()->where('company_id', $company->id)->sum('confirmed_amount_cents'));
    }

    public function test_draft_cancellation_preserves_history_without_payment_and_blocks_finalize(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $draft = $this->openDraft($user, $this->catalogProduct($company, 'self-service'));

        $this->actingAs($user)
            ->postJson("/api/app/counter-sales/{$draft['id']}/cancel", [
                'reason' => 'cliente_desistiu',
                'notes' => 'Cliente desistiu antes da pesagem.',
            ])
            ->assertOk()
            ->assertJsonPath('data.backendStatus', Order::STATUS_CANCELLED);

        $order = Order::query()->with(['payments', 'statusHistories'])->findOrFail($draft['id']);
        $this->assertCount(0, $order->payments);
        $this->assertTrue($order->statusHistories->contains('reason', 'counter_sale_draft_cancelled'));

        $this->actingAs($user)
            ->postJson("/api/app/counter-sales/{$draft['id']}/finalize", [
                'payment_method' => Payment::METHOD_PIX,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Uma comanda de balcão cancelada não pode ser finalizada.');

        $this->assertSame(0, Payment::query()->where('order_id', $order->id)->count());
    }

    public function test_open_draft_is_excluded_from_completed_history_and_is_tenant_isolated(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $draft = $this->openDraft($user, $this->catalogProduct($company, 'self-service'));

        $this->actingAs($user)
            ->getJson('/api/app/counter-sales')
            ->assertOk()
            ->assertJsonCount(0, 'data.sales')
            ->assertJsonPath('data.summary.totalSoldCents', 0)
            ->assertJsonPath('data.summary.completedSalesCount', 0);

        $otherCompany = Company::query()->create(['name' => 'Outra empresa', 'slug' => 'outra-empresa']);
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
        $otherUser->assignRole(Role::SUPER_ADMIN);

        $this->actingAs($otherUser)
            ->getJson("/api/app/counter-sales/{$draft['id']}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Esta venda de balcão não pertence ao restaurante atual.');

        $this->actingAs($otherUser)
            ->postJson("/api/app/counter-sales/{$draft['id']}/finalize", [
                'payment_method' => Payment::METHOD_PIX,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta comanda de balcão não pertence ao restaurante atual.');

        $this->actingAs($otherUser)
            ->postJson("/api/app/counter-sales/{$draft['id']}/cancel", ['reason' => 'tentativa_cross_tenant'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta venda de balcão não pertence ao restaurante atual.');

        $order = Order::query()->with('payments')->findOrFail($draft['id']);
        $this->assertSame(Order::STATUS_DRAFT, $order->status);
        $this->assertCount(0, $order->payments);
    }

    public function test_open_drafts_list_is_tenant_scoped_and_removes_finalized_or_cancelled_commands(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $selfService = $this->openDraft($user, $this->catalogProduct($company, 'self-service'));
        $standardWeight = $this->openDraft($user, $this->catalogProduct($company, 'comida-por-kg-comum'));
        Order::query()->whereKey($selfService['id'])->update(['created_at' => now()->subMinute()]);

        $this->actingAs($user)
            ->getJson('/api/app/counter-sales/drafts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $standardWeight['id'])
            ->assertJsonPath('data.0.statusLabel', 'Comanda aberta')
            ->assertJsonPath('data.0.weightPending', true)
            ->assertJsonPath('data.1.id', $selfService['id'])
            ->assertJsonPath('data.1.weightPending', false);

        $otherCompany = Company::query()->create(['name' => 'Empresa isolada', 'slug' => 'empresa-isolada']);
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
        $otherUser->assignRole(Role::SUPER_ADMIN);

        $this->actingAs($otherUser)
            ->getJson('/api/app/counter-sales/drafts')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->finalizeDraft($user, $selfService['id'], ['payment_method' => Payment::METHOD_PIX]);
        $this->actingAs($user)
            ->postJson("/api/app/counter-sales/{$standardWeight['id']}/cancel", ['reason' => 'cliente_desistiu'])
            ->assertOk();

        $this->actingAs($user)
            ->getJson('/api/app/counter-sales/drafts')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(1, Payment::query()->where('company_id', $company->id)->count());
        $this->assertSame(0, Payment::query()->where('order_id', $standardWeight['id'])->count());
    }

    public function test_draft_customer_can_be_linked_changed_removed_and_is_preserved_on_finalize_or_cancel(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $firstCustomer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Murilo Ramos',
            'phone' => '62996191921',
        ]);
        $secondCustomer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Dois',
            'phone' => '62999990000',
        ]);
        $otherCompany = Company::query()->create(['name' => 'Outro tenant', 'slug' => 'outro-tenant']);
        $otherCustomer = Customer::query()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Cliente externo',
        ]);
        $selfService = $this->catalogProduct($company, 'self-service');

        $draft = $this->actingAs($user)
            ->postJson('/api/app/counter-sales/drafts', [
                'product_id' => $selfService->id,
                'customer_id' => $firstCustomer->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.customer.id', (string) $firstCustomer->id)
            ->json('data');

        $this->actingAs($user)
            ->getJson('/api/app/counter-sales/drafts')
            ->assertOk()
            ->assertJsonPath('data.0.customer.id', (string) $firstCustomer->id)
            ->assertJsonPath('data.0.customer.name', 'Murilo Ramos')
            ->assertJsonPath('data.0.customer.phone', '62996191921');

        $initialTotal = (int) Order::query()->findOrFail($draft['id'])->total_cents;
        $this->actingAs($user)
            ->patchJson("/api/app/counter-sales/{$draft['id']}/customer", [
                'customer_id' => $secondCustomer->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.customer.id', (string) $secondCustomer->id);

        $order = Order::query()->with('payments')->findOrFail($draft['id']);
        $this->assertSame($secondCustomer->id, $order->payer_customer_id);
        $this->assertSame($initialTotal, $order->total_cents);
        $this->assertCount(0, $order->payments);

        $this->actingAs($user)
            ->patchJson("/api/app/counter-sales/{$draft['id']}/customer", ['customer_id' => null])
            ->assertOk()
            ->assertJsonPath('data.customer', null);
        $this->assertNull(Order::query()->findOrFail($draft['id'])->payer_customer_id);

        $this->actingAs($user)
            ->patchJson("/api/app/counter-sales/{$draft['id']}/customer", [
                'customer_id' => $otherCustomer->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Este cliente não pertence ao restaurante atual.');

        $this->actingAs($user)
            ->patchJson("/api/app/counter-sales/{$draft['id']}/customer", [
                'customer_id' => $firstCustomer->id,
            ])
            ->assertOk();
        $this->finalizeDraft($user, $draft['id'], ['payment_method' => Payment::METHOD_PIX]);

        $finalized = Order::query()->with('payments')->findOrFail($draft['id']);
        $this->assertSame($firstCustomer->id, $finalized->payer_customer_id);
        $this->assertSame($firstCustomer->id, $finalized->payments->sole()->customer_id);
        $this->assertSame($initialTotal, $finalized->total_cents);

        $this->actingAs($user)
            ->patchJson("/api/app/counter-sales/{$draft['id']}/customer", ['customer_id' => null])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'O cliente só pode ser alterado enquanto a comanda estiver aberta.');

        $cancelledDraft = $this->actingAs($user)
            ->postJson('/api/app/counter-sales/drafts', [
                'product_id' => $selfService->id,
                'customer_id' => $secondCustomer->id,
            ])
            ->assertCreated()
            ->json('data');
        $this->actingAs($user)
            ->postJson("/api/app/counter-sales/{$cancelledDraft['id']}/cancel", ['reason' => 'cliente_desistiu'])
            ->assertOk();

        $cancelled = Order::query()->with('payments')->findOrFail($cancelledDraft['id']);
        $this->assertSame($secondCustomer->id, $cancelled->payer_customer_id);
        $this->assertCount(0, $cancelled->payments);
    }

    public function test_walk_in_customer_survives_draft_reopen_update_finalize_and_cancel_without_implicit_registration(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $selfService = $this->catalogProduct($company, 'self-service');

        $draft = $this->actingAs($user)
            ->postJson('/api/app/counter-sales/drafts', [
                'product_id' => $selfService->id,
                'customer_name' => '  JoÃ£o   BalcÃ£o ',
                'save_customer' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.customer.name', 'JoÃ£o BalcÃ£o')
            ->assertJsonPath('data.customer.tags.0', 'Avulso')
            ->json('data');

        $initialTotal = (int) Order::query()->findOrFail($draft['id'])->total_cents;

        $this->actingAs($user)
            ->getJson('/api/app/counter-sales/drafts')
            ->assertOk()
            ->assertJsonPath('data.0.customer', null)
            ->assertJsonPath('data.0.customerSnapshot.name', 'JoÃ£o BalcÃ£o');

        $this->actingAs($user)
            ->patchJson("/api/app/counter-sales/{$draft['id']}/customer", [
                'customer_id' => null,
                'customer_name' => 'Maria Avulsa',
                'save_customer' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.customer', null)
            ->assertJsonPath('data.customerSnapshot.name', 'Maria Avulsa');

        $updated = Order::query()->with('payments')->findOrFail($draft['id']);
        $this->assertNull($updated->payer_customer_id);
        $this->assertSame('Maria Avulsa', $updated->customer_name_snapshot);
        $this->assertSame($initialTotal, $updated->total_cents);
        $this->assertCount(0, $updated->payments);
        $this->assertSame(0, Customer::query()->where('company_id', $company->id)->count());

        $savedDraft = $this->actingAs($user)
            ->patchJson("/api/app/counter-sales/{$draft['id']}/customer", [
                'customer_id' => null,
                'customer_name' => 'Maria Avulsa',
                'customer_phone' => '(62) 9 8888-7777',
                'save_customer' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Maria Avulsa')
            ->assertJsonPath('data.customerSnapshot', null)
            ->json('data');

        $this->finalizeDraft($user, $draft['id'], [
            'payment_method' => Payment::METHOD_PIX,
            'customer_id' => $savedDraft['customer']['id'],
        ]);

        $finalized = Order::query()->with('payments')->findOrFail($draft['id']);
        $savedCustomer = Customer::query()->where('company_id', $company->id)->sole();
        $this->assertSame($savedCustomer->id, $finalized->payer_customer_id);
        $this->assertSame('Maria Avulsa', $finalized->customer_name_snapshot);
        $this->assertSame('62988887777', $finalized->customer_phone_snapshot);
        $this->assertSame($savedCustomer->id, $finalized->payments->sole()->customer_id);
        $this->assertSame($initialTotal, $finalized->total_cents);

        $cancelledDraft = $this->actingAs($user)
            ->postJson('/api/app/counter-sales/drafts', [
                'product_id' => $selfService->id,
                'customer_name' => 'Cliente sem cadastro',
                'save_customer' => false,
            ])
            ->assertCreated()
            ->json('data');
        $this->actingAs($user)
            ->postJson("/api/app/counter-sales/{$cancelledDraft['id']}/cancel", ['reason' => 'cliente_desistiu'])
            ->assertOk();

        $cancelled = Order::query()->with('payments')->findOrFail($cancelledDraft['id']);
        $this->assertNull($cancelled->payer_customer_id);
        $this->assertSame('Cliente sem cadastro', $cancelled->customer_name_snapshot);
        $this->assertCount(0, $cancelled->payments);
        $this->assertSame(1, Customer::query()->where('company_id', $company->id)->count());
    }

    public function test_opening_draft_rejects_customer_from_another_tenant(): void
    {
        [$company, $user] = $this->companyAndOperator();
        $otherCompany = Company::query()->create(['name' => 'Empresa externa', 'slug' => 'empresa-externa']);
        $otherCustomer = Customer::query()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Cliente externo',
        ]);

        $this->actingAs($user)
            ->postJson('/api/app/counter-sales/drafts', [
                'product_id' => $this->catalogProduct($company, 'self-service')->id,
                'customer_id' => $otherCustomer->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Este cliente não pertence ao restaurante atual.');

        $this->assertSame(0, Order::query()->where('company_id', $company->id)->count());
        $this->assertSame(0, Payment::query()->where('company_id', $company->id)->count());
    }

    /** @return array{0: Company, 1: User} */
    private function companyAndOperator(): array
    {
        $this->seed([RoleAndPermissionSeeder::class, SolRestaurantStructuredMenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::SUPER_ADMIN);

        return [$company, $user];
    }

    private function catalogProduct(Company $company, string $slug): Product
    {
        return Product::query()
            ->where('company_id', $company->id)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function openDraft(User $user, Product $product): array
    {
        return $this->actingAs($user)
            ->postJson('/api/app/counter-sales/drafts', ['product_id' => $product->id])
            ->assertCreated()
            ->json('data');
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function finalizeDraft(User $user, string $orderId, array $attributes): array
    {
        return $this->actingAs($user)
            ->postJson("/api/app/counter-sales/{$orderId}/finalize", $attributes)
            ->assertOk()
            ->json('data');
    }
}
