<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Payments\PaymentWorkflowService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_manager_reads_and_updates_only_safe_payment_settings(): void
    {
        [$manager, $company] = $this->manager();
        $company->setting()->create([
            'settings' => ['payments' => ['pix' => ['public_key' => '62 99999-0000', 'api_token' => 'secret-token']]],
        ]);

        $this->actingAs($manager)
            ->getJson('/api/app/settings/payments')
            ->assertOk()
            ->assertJsonPath('data.pix.public_key', '62 99999-0000')
            ->assertJsonMissingPath('data.pix.api_token')
            ->assertJsonMissingPath('data.settings');

        $payload = $this->payload(['pix' => false]);
        $this->actingAs($manager)
            ->patchJson('/api/app/settings/payments', $payload)
            ->assertOk()
            ->assertJsonPath('data.methods.0.code', 'pix')
            ->assertJsonPath('data.methods.0.enabled', false);

        $this->assertSame('secret-token', data_get($company->setting->fresh()->settings, 'payments.pix.api_token'));
        $this->actingAs($manager)
            ->patchJson('/api/app/settings/payments', [...$payload, 'unexpected' => ['value' => true]])
            ->assertUnprocessable();
    }

    public function test_permissions_tenant_history_and_new_payment_eligibility_are_enforced(): void
    {
        [$manager, $company] = $this->manager();
        $unauthorized = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($unauthorized)->getJson('/api/app/settings/payments')->assertForbidden();
        $this->actingAs($unauthorized)->patchJson('/api/app/settings/payments', $this->payload())->assertForbidden();

        $other = Company::query()->create(['name' => 'Outra empresa', 'slug' => 'outra-empresa']);
        $other->setting()->create(['settings' => ['payments' => ['methods' => ['pix' => false]]]]);
        $this->actingAs($manager)->getJson('/api/app/settings/payments')->assertJsonPath('data.methods.0.enabled', true);

        $historicalOrder = $this->draft($company, 1000);
        $historicalPayment = Payment::query()->create([
            'company_id' => $company->id,
            'order_id' => $historicalOrder->id,
            'method' => Payment::METHOD_PIX,
            'provider' => Payment::PROVIDER_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
            'amount_cents' => 1000,
            'confirmed_amount_cents' => 1000,
            'currency' => 'BRL',
            'confirmed_at' => now(),
        ]);
        $this->actingAs($manager)->patchJson('/api/app/settings/payments', $this->payload(['pix' => false]))->assertOk();
        $this->assertDatabaseHas('payments', ['id' => $historicalPayment->id, 'method' => Payment::METHOD_PIX, 'status' => Payment::STATUS_CONFIRMED]);

        try {
            app(PaymentWorkflowService::class)->recordPayment($this->draft($company, 1000), ['method' => Payment::METHOD_PIX]);
            $this->fail('Pix desativado deveria bloquear uma nova seleção.');
        } catch (DomainException $exception) {
            $this->assertSame('Esta forma de pagamento não está habilitada para novos pagamentos.', $exception->getMessage());
        }
    }

    /** @return array{User, Company} */
    private function manager(): array
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        return [$user, $company];
    }

    /** @param array<string, bool> $enabled */
    private function payload(array $enabled = []): array
    {
        return [
            'methods' => array_merge([
                'pix' => true,
                'cash' => true,
                'debit_card' => true,
                'credit_card' => true,
                'customer_credit' => true,
                'other' => true,
            ], $enabled),
            'pix' => ['public_key' => '62 99999-0000'],
        ];
    }

    private function draft(Company $company, int $totalCents): Order
    {
        $order = app(OrderWorkflowService::class)->createDraft($company, [
            'customer_name_snapshot' => 'Cliente de teste',
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
        ]);
        $order->forceFill(['total_cents' => $totalCents, 'amount_due_cents' => $totalCents])->save();

        return $order->refresh();
    }
}
