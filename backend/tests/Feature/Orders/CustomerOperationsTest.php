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

        $this->assertSame(1, Customer::query()->where('company_id', $company->id)->where('phone', '(62) 90000-0000')->count());
    }
}
