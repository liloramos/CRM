<?php

namespace Tests\Feature\Champs;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BootstrapChampsAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_company_admin_and_role_without_demo_data(): void
    {
        $this->runBootstrap();

        $company = Company::query()->where('slug', 'workspace-champs')->firstOrFail();
        $user = User::query()->where('email', 'gestor@workspace-champs.example')->firstOrFail();

        $this->assertSame($company->id, $user->company_id);
        $this->assertTrue($user->hasRole(Role::ADMIN_GERENTE));
        $this->assertTrue(password_verify('Tr0pical-Segura!2026', $user->password));
        $this->assertNotSame('Tr0pical-Segura!2026', $user->password);
        $this->assertDatabaseMissing('companies', ['slug' => 'restaurante-sol']);
        $this->assertStringNotContainsString('Tr0pical-Segura!2026', Artisan::output());
    }

    public function test_second_execution_reuses_records_without_duplicates(): void
    {
        $this->runBootstrap();
        $company = Company::query()->where('slug', 'workspace-champs')->firstOrFail();
        $user = User::query()->where('email', 'gestor@workspace-champs.example')->firstOrFail();
        $passwordHash = $user->password;

        $this->runBootstrap();

        $this->assertSame(1, Company::query()->where('slug', 'workspace-champs')->count());
        $this->assertSame(1, User::query()->where('email', 'gestor@workspace-champs.example')->count());
        $this->assertSame($company->id, $user->refresh()->company_id);
        $this->assertSame($passwordHash, $user->password);
    }

    public function test_existing_user_without_company_is_assigned_safely(): void
    {
        $user = User::factory()->create([
            'company_id' => null,
            'email' => 'gestor-sem-empresa@example.test',
        ]);

        $this->artisan('champs:bootstrap-admin', [
            '--company-name' => 'Workspace Sem Empresa',
            '--slug' => 'workspace-sem-empresa',
            '--admin-name' => 'Gestor Existente',
            '--email' => $user->email,
        ])
            ->expectsQuestion('Senha do administrador:', 'Tr0pical-Segura!2026')
            ->expectsQuestion('Confirme a senha:', 'Tr0pical-Segura!2026')
            ->expectsConfirmation('Confirmar empresa Workspace Sem Empresa (workspace-sem-empresa) e administrador '.$user->name.' <'.$user->email.'>?', 'yes')
            ->assertExitCode(Command::SUCCESS);

        $this->assertNotNull($user->refresh()->company_id);
        $this->assertSame($user->name, $user->refresh()->name);
    }

    public function test_tenant_conflict_aborts_without_creating_target_company(): void
    {
        $otherCompany = Company::query()->create([
            'name' => 'Outra Empresa Fictícia',
            'slug' => 'outra-empresa-ficticia',
        ]);
        $user = User::factory()->create([
            'company_id' => $otherCompany->id,
            'email' => 'gestor-conflitante@example.test',
        ]);

        $this->artisan('champs:bootstrap-admin', [
            '--company-name' => 'Workspace Bloqueado',
            '--slug' => 'workspace-bloqueado',
            '--admin-name' => 'Gestor Conflitante',
            '--email' => $user->email,
        ])
            ->expectsOutputToContain('outra empresa')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseMissing('companies', ['slug' => 'workspace-bloqueado']);
        $this->assertSame($otherCompany->id, $user->refresh()->company_id);
    }

    private function runBootstrap(): void
    {
        $this->artisan('champs:bootstrap-admin', [
            '--company-name' => 'Workspace Champs',
            '--slug' => 'workspace-champs',
            '--admin-name' => 'Gestor Champs',
            '--email' => 'gestor@workspace-champs.example',
        ])
            ->expectsQuestion('Senha do administrador:', 'Tr0pical-Segura!2026')
            ->expectsQuestion('Confirme a senha:', 'Tr0pical-Segura!2026')
            ->expectsConfirmation('Confirmar empresa Workspace Champs (workspace-champs) e administrador Gestor Champs <gestor@workspace-champs.example>?', 'yes')
            ->assertExitCode(Command::SUCCESS);
    }
}
