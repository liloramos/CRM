<?php

namespace Tests\Feature\Orders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_search_filters_by_name_or_phone_inside_current_company(): void
    {
        $this->seed(CompanySeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $otherCompany = Company::query()->create(['name' => 'Outro Restaurante', 'slug' => 'outro-restaurante']);
        $user = User::factory()->create(['company_id' => $company->id]);

        Customer::query()->create(['company_id' => $company->id, 'name' => 'Murilo Silva', 'phone' => '(62) 99999-0001']);
        Customer::query()->create(['company_id' => $company->id, 'name' => 'Larissa Lima', 'phone' => '(62) 98888-0002']);
        Customer::query()->create(['company_id' => $otherCompany->id, 'name' => 'Murilo Outro', 'phone' => '(62) 97777-0003']);

        $byName = $this->actingAs($user)
            ->getJson('/api/app/customers?search=Mu')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $byName);
        $this->assertSame('Murilo Silva', $byName[0]['name']);

        $byPhone = $this->actingAs($user)
            ->getJson('/api/app/customers?search=99999')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $byPhone);
        $this->assertSame('Murilo Silva', $byPhone[0]['name']);
    }

    public function test_customer_creation_requires_real_record_and_rejects_duplicate_phone_in_company(): void
    {
        $this->seed(CompanySeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $otherCompany = Company::query()->create(['name' => 'Outro Restaurante', 'slug' => 'outro-restaurante']);
        $user = User::factory()->create(['company_id' => $company->id]);

        Customer::query()->create(['company_id' => $otherCompany->id, 'name' => 'Cliente Externo', 'phone' => '(62) 90000-0000']);

        $created = $this->actingAs($user)
            ->postJson('/api/app/customers', [
                'name' => 'Cliente Manual',
                'phone' => '(62) 90000-0000',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('Cliente Manual', $created['name']);

        $this->actingAs($user)
            ->postJson('/api/app/customers', [
                'name' => 'Cliente Duplicado',
                'phone' => '62900000000',
            ])
            ->assertStatus(422);

        $this->assertSame(1, Customer::query()->where('company_id', $company->id)->where('phone', '62900000000')->count());
    }

    public function test_customer_update_persists_fields_address_and_rejects_duplicate_phone(): void
    {
        $this->seed(CompanySeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Antigo',
            'phone' => '62911110000',
            'whatsapp_id' => '5562911110000',
            'whatsapp_profile_name' => 'Perfil Meta',
            'source_channel' => 'whatsapp',
        ]);
        Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Existente',
            'phone' => '62922220000',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/app/customers/{$customer->id}", [
                'name' => 'Cliente Corrigido',
                'phone' => '(62) 91111-0000',
                'email' => 'cliente.corrigido@example.test',
                'notes' => 'Preferencia registrada.',
                'address' => [
                    'street' => 'Rua Um',
                    'number' => '123',
                    'neighborhood' => 'Centro',
                    'city' => 'Goiania',
                    'reference' => 'Portao amarelo',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cliente Corrigido')
            ->assertJsonPath('data.whatsappProfileName', 'Perfil Meta')
            ->assertJsonPath('data.address.street', 'Rua Um');

        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'street' => 'Rua Um',
            'number' => '123',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/app/customers/{$customer->id}", [
                'name' => 'Cliente Duplicado',
                'phone' => '(62) 92222-0000',
            ])
            ->assertStatus(422);
    }

    public function test_customer_index_hides_demo_records_in_operational_mode(): void
    {
        $this->seed(CompanySeeder::class);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);

        Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Real',
            'phone' => '62999990000',
        ]);
        Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Exemplo',
            'email' => Customer::DEMO_EMAIL,
            'source_channel' => Customer::SOURCE_CHANNEL_DEMO,
        ]);

        $payload = $this->actingAs($user)->getJson('/api/app/customers')->assertOk()->json('data');

        $this->assertCount(1, $payload);
        $this->assertSame('Cliente Real', $payload[0]['name']);
    }

    public function test_customer_can_be_created_with_multiple_addresses_and_only_one_default(): void
    {
        $this->seed(CompanySeeder::class);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);

        $customer = $this->actingAs($user)->postJson('/api/app/customers', [
            'name' => 'Cliente com endereços',
            'phone' => '62999991111',
            'addresses' => [
                $this->addressPayload('Casa', 'Rua A', true),
                $this->addressPayload('Trabalho', 'Rua B', false),
            ],
        ])->assertCreated()
            ->assertJsonCount(2, 'data.addresses')
            ->assertJsonPath('data.addresses.0.label', 'Casa')
            ->assertJsonPath('data.addresses.0.is_default', true)
            ->json('data');

        $this->assertSame(1, CustomerAddress::query()->where('customer_id', $customer['id'])->where('is_default', true)->count());
    }

    public function test_address_crud_changes_default_and_reassigns_it_after_safe_removal(): void
    {
        $this->seed(CompanySeeder::class);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente endereço']);

        $home = $this->actingAs($user)->postJson("/api/app/customers/{$customer->id}/addresses", $this->addressPayload('Casa', 'Rua A'))
            ->assertCreated()->assertJsonPath('data.is_default', true)->json('data');
        $work = $this->actingAs($user)->postJson("/api/app/customers/{$customer->id}/addresses", $this->addressPayload('Trabalho', 'Rua B'))
            ->assertCreated()->assertJsonPath('data.is_default', false)->json('data');

        $this->actingAs($user)->patchJson("/api/app/customers/{$customer->id}/addresses/{$work['id']}", [
            ...$this->addressPayload('Escritório', 'Rua C'),
            'number' => '300',
        ])->assertOk()->assertJsonPath('data.street', 'Rua C');

        $this->actingAs($user)->postJson("/api/app/customers/{$customer->id}/addresses/{$work['id']}/default")
            ->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertFalse(CustomerAddress::query()->findOrFail($home['id'])->is_default);

        $this->actingAs($user)->deleteJson("/api/app/customers/{$customer->id}/addresses/{$work['id']}")->assertOk();
        $this->assertTrue(CustomerAddress::query()->findOrFail($home['id'])->is_default);
    }

    public function test_address_removal_preserves_order_snapshot_and_blocks_legacy_order_without_snapshot(): void
    {
        $this->seed(CompanySeeder::class);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente histórico']);
        $address = CustomerAddress::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            ...$this->addressPayload('Casa', 'Rua Histórica', true),
        ]);
        $order = Order::query()->create([
            'company_id' => $company->id,
            'payer_customer_id' => $customer->id,
            'delivery_address_id' => $address->id,
            'order_date' => now()->toDateString(),
            'daily_sequence' => 1,
            'code' => 'HIST-1',
            'fulfillment_type' => Order::FULFILLMENT_DELIVERY,
        ]);

        $this->actingAs($user)->deleteJson("/api/app/customers/{$customer->id}/addresses/{$address->id}")
            ->assertStatus(422);

        $snapshot = ['street' => 'Rua Histórica', 'number' => '100', 'latitude' => -16.3, 'longitude' => -48.9];
        $order->forceFill(['delivery_address_snapshot' => $snapshot])->save();
        $this->actingAs($user)->deleteJson("/api/app/customers/{$customer->id}/addresses/{$address->id}")->assertOk();

        $this->assertNull($order->refresh()->delivery_address_id);
        $this->assertSame($snapshot, $order->delivery_address_snapshot);
    }

    public function test_address_endpoints_enforce_authentication_and_tenant_ownership(): void
    {
        $this->seed(CompanySeeder::class);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $other = Company::query()->create(['name' => 'Outro', 'slug' => 'outro-endereco']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::query()->create(['company_id' => $other->id, 'name' => 'Externo']);
        $address = CustomerAddress::query()->create(['company_id' => $other->id, 'customer_id' => $customer->id, ...$this->addressPayload('Casa', 'Rua X', true)]);

        $this->postJson("/api/app/customers/{$customer->id}/addresses", $this->addressPayload('Casa', 'Rua X'))->assertUnauthorized();
        $this->actingAs($user)->patchJson("/api/app/customers/{$customer->id}/addresses/{$address->id}", $this->addressPayload('Casa', 'Rua Y'))->assertNotFound();
    }

    public function test_customer_address_backfill_normalizes_labels_tenant_and_single_default(): void
    {
        $this->seed(CompanySeeder::class);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $wrongCompany = Company::query()->create(['name' => 'Errada', 'slug' => 'errada']);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Legado']);

        DB::statement('DROP INDEX IF EXISTS customer_addresses_one_default_per_customer');
        CustomerAddress::query()->create(['company_id' => $wrongCompany->id, 'customer_id' => $customer->id, ...$this->addressPayload('', 'Rua 1', true)]);
        CustomerAddress::query()->create(['company_id' => $wrongCompany->id, 'customer_id' => $customer->id, ...$this->addressPayload('', 'Rua 2', true)]);

        $migration = require database_path('migrations/2026_09_12_000013_harden_customer_address_defaults.php');
        $migration->up();

        $addresses = CustomerAddress::query()->where('customer_id', $customer->id)->get();
        $this->assertSame(1, $addresses->where('is_default', true)->count());
        $this->assertTrue($addresses->every(fn (CustomerAddress $address): bool => $address->label === 'Principal' && (int) $address->company_id === (int) $company->id));
    }

    public function test_recurring_customer_order_can_select_saved_address_without_losing_snapshot(): void
    {
        $this->seed(CompanySeeder::class);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente recorrente', 'phone' => '62999990000']);
        $home = CustomerAddress::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, ...$this->addressPayload('Casa', 'Rua Casa', true)]);
        CustomerAddress::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, ...$this->addressPayload('Trabalho', 'Rua Trabalho')]);

        $orderId = $this->actingAs($user)->postJson('/api/app/orders/drafts', [
            'payer_customer_id' => $customer->id,
            'fulfillment_type' => 'delivery',
            'delivery_address_id' => $home->id,
        ])->assertCreated()
            ->json('data.id');

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame($home->id, $order->delivery_address_id);
        $this->assertSame('Rua Casa', $order->delivery_address_snapshot['street']);

        $home->forceFill(['street' => 'Rua Casa Alterada'])->save();
        $this->assertSame('Rua Casa', $order->refresh()->delivery_address_snapshot['street']);
    }

    public function test_order_accepts_temporary_address_without_saving_it_to_customer(): void
    {
        $this->seed(CompanySeeder::class);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente temporário']);

        $orderId = $this->actingAs($user)->postJson('/api/app/orders/drafts', [
            'payer_customer_id' => $customer->id,
            'fulfillment_type' => 'delivery',
            'delivery_address' => $this->addressPayload('Entrega', 'Rua Temporária'),
            'save_delivery_address' => false,
        ])->assertCreated()->json('data.id');

        $order = Order::query()->findOrFail($orderId);
        $this->assertNull($order->delivery_address_id);
        $this->assertSame('Rua Temporária', $order->delivery_address_snapshot['street']);
        $this->assertSame(0, $customer->addresses()->count());
    }

    public function test_order_rejects_saved_address_from_another_customer_or_company(): void
    {
        $this->seed(CompanySeeder::class);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $other = Company::query()->create(['name' => 'Outra', 'slug' => 'outra-pedido']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente local']);
        $externalCustomer = Customer::query()->create(['company_id' => $other->id, 'name' => 'Cliente externo']);
        $externalAddress = CustomerAddress::query()->create(['company_id' => $other->id, 'customer_id' => $externalCustomer->id, ...$this->addressPayload('Casa', 'Rua Externa', true)]);

        $this->actingAs($user)->postJson('/api/app/orders/drafts', [
            'payer_customer_id' => $customer->id,
            'fulfillment_type' => 'delivery',
            'delivery_address_id' => $externalAddress->id,
        ])->assertStatus(422);

        $this->assertSame(0, Order::query()->where('company_id', $company->id)->count());
    }

    /** @return array<string, mixed> */
    private function addressPayload(string $label, string $street, bool $default = false): array
    {
        return [
            'label' => $label,
            'street' => $street,
            'number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'Anápolis',
            'state' => 'GO',
            'postal_code' => '75000-000',
            'reference' => 'Portão azul',
            'is_default' => $default,
        ];
    }
}
