<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\OperatingHourException;
use App\Models\Role;
use App\Models\User;
use App\Services\Operational\CompanyOperatingHoursService;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneralSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_reads_and_updates_canonical_operating_hours(): void
    {
        [$manager, $company] = $this->user(Role::ADMIN_GERENTE);
        CompanySetting::query()->updateOrCreate(['company_id' => $company->id], ['timezone' => 'America/Sao_Paulo']);
        $this->actingAs($manager)->getJson('/api/app/settings/general')->assertOk()->assertJsonPath('data.timezone', 'America/Sao_Paulo')->assertJsonCount(7, 'data.operating_hours')->assertJsonMissingPath('data.settings');
        $hours = collect(range(0, 6))->map(fn (int $weekday) => ['weekday' => $weekday, 'is_open' => $weekday === 2, 'opens_at' => $weekday === 2 ? '11:00' : null, 'closes_at' => $weekday === 2 ? '15:00' : null])->all();
        $this->actingAs($manager)->patchJson('/api/app/settings/general', ['timezone' => 'America/Sao_Paulo', 'operating_hours' => $hours])->assertOk()->assertJsonPath('data.operating_hours.2.opens_at', '11:00');
        $this->assertDatabaseHas('operating_hours', ['company_id' => $company->id, 'weekday' => 2, 'opens_at' => '11:00', 'closes_at' => '15:00', 'is_open' => true]);
        $this->assertSame('OPEN', app(CompanyOperatingHoursService::class)->status($company->fresh(), CarbonImmutable::parse('2026-09-01 12:00:00', 'America/Sao_Paulo'))['status']);
    }

    public function test_invalid_hours_and_unauthorized_users_are_rejected(): void
    {
        [$attendant] = $this->user(Role::ATENDENTE);
        $this->actingAs($attendant)->getJson('/api/app/settings/general')->assertForbidden();
        $this->actingAs($attendant)->patchJson('/api/app/settings/general', [])->assertForbidden();
        [$manager] = $this->user(Role::ADMIN_GERENTE);
        $hours = collect(range(0, 6))->map(fn (int $weekday) => ['weekday' => $weekday, 'is_open' => true, 'opens_at' => '15:00', 'closes_at' => '11:00'])->all();
        $this->actingAs($manager)->patchJson('/api/app/settings/general', ['timezone' => 'Invalid/Timezone', 'operating_hours' => $hours])->assertUnprocessable();
    }

    public function test_manager_can_save_date_exception_and_it_overrides_weekly_hours(): void
    {
        [$manager, $company] = $this->user(Role::ADMIN_GERENTE);
        $hours = collect(range(0, 6))->map(fn (int $weekday) => ['weekday' => $weekday, 'is_open' => $weekday === 2, 'opens_at' => $weekday === 2 ? '11:00' : null, 'closes_at' => $weekday === 2 ? '15:00' : null])->all();

        $response = $this->actingAs($manager)->patchJson('/api/app/settings/general', [
            'timezone' => 'America/Sao_Paulo',
            'operating_hours' => $hours,
            'operating_exceptions' => [['date' => '2026-09-01', 'is_open' => false, 'opens_at' => null, 'closes_at' => null, 'notes' => 'Feriado local']],
        ]);

        $this->assertDatabaseHas('operating_hour_exceptions', ['company_id' => $company->id, 'date' => '2026-09-01', 'is_open' => false]);
        $response->assertOk()->assertJsonPath('data.operating_exceptions.0.notes', 'Feriado local');
        $this->assertSame('CLOSED', app(CompanyOperatingHoursService::class)->status($company->fresh(), CarbonImmutable::parse('2026-09-01 12:00:00', 'America/Sao_Paulo'))['status']);
        $this->assertInstanceOf(OperatingHourException::class, OperatingHourException::query()->firstOrFail());
    }

    private function user(string $role): array
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole($role);

        return [$user, $company];
    }
}
