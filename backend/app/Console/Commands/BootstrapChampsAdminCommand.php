<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class BootstrapChampsAdminCommand extends Command
{
    protected $signature = 'champs:bootstrap-admin
        {--company-name= : Nome da empresa}
        {--slug= : Slug da empresa}
        {--admin-name= : Nome do administrador}
        {--email= : E-mail do administrador}';

    protected $description = 'Cria uma empresa Champs e seu primeiro administrador com segurança';

    public function handle(): int
    {
        $companyName = $this->valueOrAsk('company-name', 'Nome da empresa:');
        $slugInput = $this->valueOrAsk('slug', 'Slug da empresa:');
        $adminName = $this->valueOrAsk('admin-name', 'Nome do administrador:');
        $email = Str::lower($this->valueOrAsk('email', 'E-mail do administrador:'));
        $slug = Str::slug($slugInput);

        $validation = Validator::make([
            'company_name' => $companyName,
            'slug' => $slug,
            'admin_name' => $adminName,
            'email' => $email,
        ], [
            'company_name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'admin_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $company = Company::query()->where('slug', $slug)->first();
        $user = User::query()->where('email', $email)->first();

        if ($user && $user->company_id !== null && $user->company_id !== $company?->id) {
            $this->error('O usuário já pertence a outra empresa. Use champs:assign-user-company para uma associação explícita.');

            return self::FAILURE;
        }

        $password = $this->secret('Senha do administrador:');
        $passwordConfirmation = $this->secret('Confirme a senha:');

        $passwordValidation = Validator::make([
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        if ($passwordValidation->fails()) {
            foreach ($passwordValidation->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $companyLabel = $company?->name ?? $companyName;
        $userLabel = $user?->name ?? $adminName;

        if (! $this->confirm(
            "Confirmar empresa {$companyLabel} ({$slug}) e administrador {$userLabel} <{$email}>?",
        )) {
            $this->warn('Operação cancelada.');

            return self::FAILURE;
        }

        app(RoleAndPermissionSeeder::class)->run();

        DB::transaction(function () use ($adminName, $company, $companyName, $email, $password, $slug, $user): void {
            $targetCompany = $company ?? Company::query()->create([
                'name' => $companyName,
                'slug' => $slug,
            ]);

            $targetCompany->setting()->firstOrCreate([], [
                'timezone' => 'America/Sao_Paulo',
            ]);

            $targetUser = $user ?? User::query()->create([
                'company_id' => $targetCompany->id,
                'name' => $adminName,
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make($password),
            ]);

            if ($targetUser->company_id === null) {
                $targetUser->forceFill(['company_id' => $targetCompany->id])->save();
            }

            $targetUser->assignRole(Role::ADMIN_GERENTE);
        });

        $this->info("Empresa {$companyLabel} e administrador {$email} estão prontos.");
        $this->line('Role atribuída: admin_gerente.');

        return self::SUCCESS;
    }

    private function valueOrAsk(string $option, string $question): string
    {
        $value = trim((string) $this->option($option));

        return $value !== '' ? $value : trim((string) $this->ask($question));
    }
}
