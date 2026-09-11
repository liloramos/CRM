<?php

namespace Tests\Feature\Orders;

use App\Models\Company;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Operational\OperationalCrmPresenter;
use App\Services\Orders\OrderWorkflowService;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SolRestaurantStructuredMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_counter_sale_persists_an_explicit_seller_distinct_from_the_authenticated_actor(): void
    {
        [$company, $operator] = $this->companyAndOperator();
        $seller = $this->seller($company, 'Beatriz');
        $product = Product::query()->where('company_id', $company->id)->where('slug', 'self-service')->firstOrFail();

        $data = $this->actingAs($operator)
            ->postJson('/api/app/counter-sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => Payment::METHOD_CASH,
                'seller_user_id' => $seller->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.seller.id', (string) $seller->id)
            ->assertJsonPath('data.sellerName', 'Beatriz')
            ->json('data');

        $order = Order::query()->with('statusHistories')->findOrFail($data['id']);
        $created = $order->statusHistories->firstWhere('reason', 'order_created');

        $this->assertSame($operator->id, $order->created_by_user_id);
        $this->assertSame($seller->id, $order->seller_user_id);
        $this->assertSame('Beatriz', $order->seller_name_snapshot);
        $this->assertNotSame($order->created_by_user_id, $order->seller_user_id);
        $this->assertSame($seller->id, data_get($created?->metadata, 'seller_user_id'));
        $this->assertSame('Beatriz', data_get($created?->metadata, 'seller_name'));
    }

    public function test_seller_change_is_audited_with_snapshots_and_does_not_follow_later_user_renames(): void
    {
        [$company, $operator] = $this->companyAndOperator();
        $larissa = $this->seller($company, 'Larissa');
        $beatriz = $this->seller($company, 'Beatriz');
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'created_by_user_id' => $operator->id,
            'seller_user_id' => $larissa->id,
            'origin_channel' => Order::CHANNEL_MANUAL,
        ]);

        $this->actingAs($operator)
            ->patchJson("/api/app/orders/{$order->id}/seller", ['seller_user_id' => $beatriz->id])
            ->assertOk()
            ->assertJsonPath('data.seller.id', (string) $beatriz->id)
            ->assertJsonPath('data.sellerName', 'Beatriz')
            ->assertJsonFragment(['description' => 'Responsável alterado: Larissa → Beatriz.']);

        $history = $order->statusHistories()->where('reason', 'order_seller_changed')->sole();
        $this->assertSame($operator->id, $history->user_id);
        $this->assertSame($larissa->id, data_get($history->metadata, 'previous_seller_user_id'));
        $this->assertSame('Larissa', data_get($history->metadata, 'previous_seller_name'));
        $this->assertSame($beatriz->id, data_get($history->metadata, 'seller_user_id'));
        $this->assertSame('Beatriz', data_get($history->metadata, 'seller_name'));

        $beatriz->update(['name' => 'Beatriz Souza']);

        $this->assertSame('Beatriz', $order->refresh()->seller_name_snapshot);
        $this->assertSame('Beatriz Souza', $order->seller()->firstOrFail()->name);
    }

    public function test_tenant_isolation_rejects_external_sellers_and_candidate_list_is_company_scoped(): void
    {
        [$company, $operator] = $this->companyAndOperator();
        $seller = $this->seller($company, 'Helton');
        $otherCompany = Company::query()->create(['name' => 'Outra empresa', 'slug' => 'outra-empresa-seller']);
        $externalSeller = $this->seller($otherCompany, 'Pessoa externa');
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'created_by_user_id' => $operator->id,
            'seller_user_id' => $seller->id,
        ]);

        $this->actingAs($operator)
            ->patchJson("/api/app/orders/{$order->id}/seller", ['seller_user_id' => $externalSeller->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'O responsável selecionado não está habilitado para vendas neste restaurante.');

        $this->assertSame($seller->id, $order->refresh()->seller_user_id);

        $snapshot = app(OperationalCrmPresenter::class)->snapshot($company, $operator);
        $candidateIds = collect($snapshot['sellerCandidates'])->pluck('id');

        $this->assertTrue($candidateIds->contains((string) $seller->id));
        $this->assertFalse($candidateIds->contains((string) $externalSeller->id));
    }

    public function test_candidates_depend_on_explicit_eligibility_and_not_role_or_access_permissions(): void
    {
        [$company, $operator] = $this->companyAndOperator();
        $attendantWithoutEligibility = User::factory()->create([
            'company_id' => $company->id,
            'name' => 'Atendente sem elegibilidade',
            'can_be_seller' => false,
        ]);
        $attendantWithoutEligibility->assignRole(Role::ATENDENTE);
        $eligibleManager = User::factory()->create([
            'company_id' => $company->id,
            'name' => 'Gerente vendedor',
            'can_be_seller' => true,
        ]);
        $eligibleManager->assignRole(Role::ADMIN_GERENTE);
        $inactiveEligible = User::factory()->create([
            'company_id' => $company->id,
            'name' => 'Vendedor inativo',
            'can_be_seller' => true,
            'is_active' => false,
        ]);
        $inactiveEligible->assignRole(Role::ATENDENTE);

        $snapshot = app(OperationalCrmPresenter::class)->snapshot($company, $operator);
        $candidateIds = collect($snapshot['sellerCandidates'])->pluck('id');

        $this->assertFalse((bool) $operator->can_be_seller);
        $this->assertFalse($candidateIds->contains((string) $operator->id));
        $this->assertFalse($candidateIds->contains((string) $attendantWithoutEligibility->id));
        $this->assertFalse($candidateIds->contains((string) $inactiveEligible->id));
        $this->assertTrue($candidateIds->contains((string) $eligibleManager->id));
    }

    public function test_ineligible_user_cannot_be_assigned_by_manual_api_payload(): void
    {
        [$company, $operator] = $this->companyAndOperator();
        $ineligible = User::factory()->create([
            'company_id' => $company->id,
            'can_be_seller' => false,
        ]);
        $ineligible->assignRole(Role::ATENDENTE);
        $order = app(OrderWorkflowService::class)->createDraft($company);
        $product = Product::query()
            ->where('company_id', $company->id)
            ->where('slug', 'self-service')
            ->firstOrFail();

        $this->actingAs($operator)
            ->patchJson("/api/app/orders/{$order->id}/seller", ['seller_user_id' => $ineligible->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'O responsável selecionado não está habilitado para vendas neste restaurante.');

        $this->actingAs($operator)
            ->postJson('/api/app/orders/drafts', [
                'customer_name_snapshot' => 'Cliente avulso',
                'seller_user_id' => $ineligible->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'O responsável selecionado não está habilitado para vendas neste restaurante.');

        $this->actingAs($operator)
            ->postJson('/api/app/counter-sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => Payment::METHOD_CASH,
                'seller_user_id' => $ineligible->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'O responsável selecionado não está habilitado para vendas neste restaurante.');

        $this->assertNull($order->refresh()->seller_user_id);
    }

    public function test_removing_eligibility_preserves_existing_assignment_and_name_snapshot(): void
    {
        [$company, $operator] = $this->companyAndOperator();
        $seller = $this->seller($company, 'Beatriz');
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'seller_user_id' => $seller->id,
        ]);

        $seller->update(['can_be_seller' => false, 'name' => 'Beatriz Souza']);

        $this->actingAs($operator)
            ->patchJson("/api/app/orders/{$order->id}/seller", ['seller_user_id' => $seller->id])
            ->assertOk()
            ->assertJsonPath('data.sellerName', 'Beatriz');

        $order->refresh();
        $this->assertSame($seller->id, $order->seller_user_id);
        $this->assertSame('Beatriz', $order->seller_name_snapshot);
        $this->assertSame(0, $order->statusHistories()->where('reason', 'order_seller_changed')->count());

        $candidateIds = collect(app(OperationalCrmPresenter::class)->snapshot($company, $operator)['sellerCandidates'])->pluck('id');
        $this->assertFalse($candidateIds->contains((string) $seller->id));

        $this->actingAs($operator)
            ->patchJson("/api/app/orders/{$order->id}/seller", ['seller_user_id' => null])
            ->assertOk()
            ->assertJsonPath('data.sellerName', null);
    }

    public function test_automatic_whatsapp_order_stays_unassigned_until_a_human_assigns_it_and_lifecycle_does_not_overwrite_it(): void
    {
        [$company, $operator] = $this->companyAndOperator();
        $seller = $this->seller($company, 'Calebe');
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company, [
            'origin_channel' => Order::CHANNEL_WHATSAPP,
            'entry_mode' => Order::CHANNEL_WHATSAPP,
            'is_manual' => false,
        ]);

        $this->assertNull($order->seller_user_id);
        $this->assertNull($order->seller_name_snapshot);

        $orders->assignSeller($company, $order, $seller->id, $operator);
        $orders->transitionTo($order->refresh(), Order::STATUS_CONFIRMED, $operator);

        $this->assertSame(Order::CHANNEL_WHATSAPP, $order->refresh()->origin_channel);
        $this->assertSame($seller->id, $order->seller_user_id);
        $this->assertSame('Calebe', $order->seller_name_snapshot);
        $this->assertSame(1, $order->statusHistories()->where('reason', 'order_seller_changed')->count());
    }

    public function test_manual_order_can_start_unassigned_then_be_assigned_only_by_existing_order_rbac(): void
    {
        [$company, $operator] = $this->companyAndOperator();
        $seller = $this->seller($company, 'Larissa');

        $data = $this->actingAs($operator)
            ->postJson('/api/app/orders/drafts', [
                'customer_name_snapshot' => 'Cliente avulso',
                'seller_user_id' => $seller->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.sellerName', 'Larissa')
            ->json('data');

        $order = Order::query()->findOrFail($data['id']);
        $unprivileged = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($unprivileged)
            ->patchJson("/api/app/orders/{$order->id}/seller", ['seller_user_id' => null])
            ->assertForbidden();

        $this->assertSame($seller->id, $order->refresh()->seller_user_id);

        $this->actingAs($operator)
            ->patchJson("/api/app/orders/{$order->id}/seller", ['seller_user_id' => null])
            ->assertOk()
            ->assertJsonPath('data.seller', null)
            ->assertJsonPath('data.sellerName', null);
    }

    /** @return array{0: Company, 1: User} */
    private function companyAndOperator(): array
    {
        $this->seed([RoleAndPermissionSeeder::class, SolRestaurantStructuredMenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $operator = User::factory()->create(['company_id' => $company->id, 'name' => 'Login compartilhado']);
        $operator->assignRole(Role::SUPER_ADMIN);

        return [$company, $operator];
    }

    private function seller(Company $company, string $name): User
    {
        $seller = User::factory()->create([
            'company_id' => $company->id,
            'name' => $name,
            'can_be_seller' => true,
        ]);
        $seller->assignRole(Role::ATENDENTE);

        return $seller;
    }
}
