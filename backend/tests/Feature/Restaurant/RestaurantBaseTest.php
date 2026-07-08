<?php

namespace Tests\Feature\Restaurant;

use App\Models\Company;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RestaurantBaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_base_seed_creates_safe_company_context(): void
    {
        $this->seed([
            CompanySeeder::class,
            RestaurantBaseSeeder::class,
        ]);

        $company = Company::query()
            ->where('slug', 'restaurante-sol')
            ->firstOrFail();

        $this->assertDatabaseHas('restaurant_profiles', [
            'company_id' => $company->id,
            'display_name' => 'Restaurante Sol',
            'contact_phone' => null,
            'address_line' => null,
        ]);

        $this->assertDatabaseHas('company_settings', [
            'company_id' => $company->id,
            'status' => 'active',
            'timezone' => 'America/Sao_Paulo',
            'locale' => 'pt_BR',
            'currency' => 'BRL',
            'default_attendance_mode' => 'manual',
        ]);

        $this->assertSame(7, $company->operatingHours()->count());
    }

    public function test_company_exposes_restaurant_base_relationships(): void
    {
        $company = Company::query()->create([
            'name' => 'Restaurante Demo',
            'slug' => 'restaurante-demo',
        ]);

        $company->restaurantProfile()->create([
            'display_name' => 'Restaurante Demo',
        ]);

        $company->setting()->create([
            'status' => 'active',
        ]);

        $company->operatingHours()->create([
            'weekday' => 1,
            'is_open' => false,
        ]);

        $company->load(['restaurantProfile', 'setting', 'operatingHours']);

        $this->assertSame('Restaurante Demo', $company->restaurantProfile->display_name);
        $this->assertSame('active', $company->setting->status);
        $this->assertCount(1, $company->operatingHours);
    }
}
