<?php

namespace Tests\Feature\Restaurant;

use App\Models\Company;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RestaurantBaseSeeder;
use Database\Seeders\SolRestaurantOperatingHoursSeeder;
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

    public function test_sol_official_operating_hours_are_stored_in_the_canonical_schedule(): void
    {
        $this->seed([
            CompanySeeder::class,
            RestaurantBaseSeeder::class,
            SolRestaurantOperatingHoursSeeder::class,
        ]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $hours = $company->operatingHours()->orderBy('weekday')->get()->keyBy('weekday');

        $this->assertFalse($hours->get(0)->is_open);
        $this->assertNull($hours->get(0)->opens_at);
        foreach (range(1, 6) as $weekday) {
            $this->assertTrue($hours->get($weekday)->is_open);
            $this->assertSame('10:30', $hours->get($weekday)->opens_at);
            $this->assertSame('14:00', $hours->get($weekday)->closes_at);
        }
        $this->assertSame('America/Sao_Paulo', $company->setting()->value('timezone'));
    }
}
