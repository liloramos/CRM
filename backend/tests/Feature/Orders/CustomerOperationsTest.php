<?php

namespace Tests\Feature\Orders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
