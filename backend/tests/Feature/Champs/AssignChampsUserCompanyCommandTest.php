<?php

namespace Tests\Feature\Champs;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\UserAccessSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignChampsUserCompanyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_assigns_user_idempotently_and_preserves_restaurante_sol(): void
    {
        $sol = Company::query()->create([
            'name' => 'Restaurante Sol',
            'slug' => 'restaurante-sol',
        ]);
        $champs = Company::query()->create([
            'name' => 'Empresa Champs Fictícia',
            'slug' => 'empresa-champs-ficticia',
        ]);
        $user = User::factory()->create([
            'company_id' => $sol->id,
            'email' => 'admin.gerente@example.test',
        ]);

        foreach (range(1, 2) as $run) {
            $this->artisan('champs:assign-user-company', [
                'email' => $user->email,
                'company' => $champs->slug,
                '--force' => true,
            ])
                ->expectsOutputToContain('Usuário associado à empresa Empresa Champs Fictícia')
                ->assertExitCode(Command::SUCCESS);
        }

        $this->assertSame($champs->id, $user->refresh()->company_id);
        $this->assertSame('Restaurante Sol', $sol->refresh()->name);
        $this->assertSame('restaurante-sol', $sol->slug);
        $this->assertSame(2, Company::query()->count());

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(UserAccessSeeder::class);

        $this->assertSame($champs->id, $user->refresh()->company_id);
        $this->assertSame(2, Company::query()->count());
    }

    public function test_command_creates_missing_company_only_with_explicit_name(): void
    {
        $user = User::factory()->create(['email' => 'owner.ficticio@example.test']);

        $this->artisan('champs:assign-user-company', [
            'email' => $user->email,
            'company' => 'workspace-ficticio',
            '--name' => 'Workspace Fictício',
            '--force' => true,
        ])->assertExitCode(Command::SUCCESS);

        $company = Company::query()->where('slug', 'workspace-ficticio')->firstOrFail();

        $this->assertSame($company->id, $user->refresh()->company_id);
        $this->assertSame('America/Sao_Paulo', $company->setting?->timezone);
    }

    public function test_command_fails_without_mutation_when_user_or_company_is_missing(): void
    {
        $user = User::factory()->create(['email' => 'gestor.ficticio@example.test']);

        $this->artisan('champs:assign-user-company', [
            'email' => 'ausente@example.test',
            'company' => 'empresa-ausente',
            '--force' => true,
        ])->assertExitCode(Command::FAILURE);

        $this->artisan('champs:assign-user-company', [
            'email' => $user->email,
            'company' => 'empresa-ausente',
            '--force' => true,
        ])->assertExitCode(Command::FAILURE);

        $this->assertNull($user->refresh()->company_id);
        $this->assertDatabaseMissing('companies', ['slug' => 'empresa-ausente']);
    }
}
