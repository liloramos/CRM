<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssignChampsUserCompanyCommand extends Command
{
    protected $signature = 'champs:assign-user-company
        {email : E-mail exato do usuário}
        {company : Slug da empresa de destino}
        {--name= : Nome usado somente para criar explicitamente uma empresa ausente}
        {--force : Ignorar confirmação apenas em local ou testing}';

    protected $description = 'Associa explicitamente um usuário a uma empresa sem alterar outros tenants';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $slug = Str::slug((string) $this->argument('company'));
        $name = trim((string) $this->option('name'));
        $isLocal = $this->laravel->environment(['local', 'testing']);
        $force = (bool) $this->option('force');

        if ($email === '' || $slug === '') {
            $this->error('Informe um e-mail e um slug de empresa válidos.');

            return self::FAILURE;
        }

        if ($force && ! $isLocal) {
            $this->error('A opção --force só pode ser usada em local ou testing.');

            return self::FAILURE;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            $this->error('Usuário não encontrado. Nenhuma alteração foi realizada.');

            return self::FAILURE;
        }

        $company = Company::query()->where('slug', $slug)->first();

        if (! $company && $name === '') {
            $this->error('Empresa não encontrada. Use --name para criá-la explicitamente.');

            return self::FAILURE;
        }

        $targetName = $company?->name ?? $name;

        if (! $force && ! $this->confirm(
            "Associar {$user->email} à empresa {$targetName} ({$slug})?",
        )) {
            $this->warn('Operação cancelada.');

            return self::FAILURE;
        }

        $company = DB::transaction(function () use ($company, $name, $slug, $user): Company {
            $target = $company ?? Company::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name],
            );

            $target->setting()->firstOrCreate([], [
                'timezone' => 'America/Sao_Paulo',
            ]);

            if ($user->company_id !== $target->id) {
                $user->forceFill(['company_id' => $target->id])->save();
            }

            return $target;
        });

        $this->info("Usuário associado à empresa {$company->name} ({$company->slug}).");

        return self::SUCCESS;
    }
}
