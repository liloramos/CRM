<?php

namespace Tests\Feature\Ai;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigureOperationalDayCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_a_missing_operational_day_start_time_for_an_explicit_company(): void
    {
        $company = Company::query()->create(['name' => 'Restaurante', 'slug' => 'restaurante']);
        $company->setting()->create(['timezone' => 'America/Sao_Paulo', 'settings' => []]);

        $this->artisan('company:configure-operational-day', ['--company' => 'restaurante', '--start' => '00:00'])
            ->assertSuccessful();

        $this->assertSame('00:00', $company->fresh('setting')->setting->settings['operational_day_start_time']);
    }

    public function test_it_preserves_an_existing_value_without_force(): void
    {
        $company = Company::query()->create(['name' => 'Restaurante', 'slug' => 'restaurante']);
        $company->setting()->create(['timezone' => 'America/Sao_Paulo', 'settings' => ['operational_day_start_time' => '04:00']]);

        $this->artisan('company:configure-operational-day', ['--company' => 'restaurante', '--start' => '00:00'])
            ->expectsOutputToContain('preservado')
            ->assertSuccessful();

        $this->assertSame('04:00', $company->fresh('setting')->setting->settings['operational_day_start_time']);
    }

    public function test_it_rejects_an_invalid_time_or_unknown_company(): void
    {
        $this->artisan('company:configure-operational-day', ['--company' => 'ausente', '--start' => '00:00'])
            ->assertFailed();
        $this->artisan('company:configure-operational-day', ['--company' => 'ausente', '--start' => '25:00'])
            ->assertFailed();
    }
}
