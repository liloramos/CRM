<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairLocalAdminCompanyCommand extends Command
{
    protected $signature = 'app:repair-local-admin-company';

    protected $description = 'Align the local demo administrator with Restaurante Sol.';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('This local repair is disabled in production.');

            return self::FAILURE;
        }

        $company = Company::query()->where('slug', 'restaurante-sol')->first();
        $user = User::query()->where('email', 'admin.gerente@example.test')->first();

        if ($company === null || $user === null) {
            $this->error('The local administrator or Restaurante Sol company was not found.');

            return self::FAILURE;
        }

        $before = $user->company_id;

        DB::transaction(function () use ($user, $company): void {
            $user->forceFill(['company_id' => $company->id])->save();
        });

        $this->table(['Item', 'Valor'], [
            ['user_id', $user->id],
            ['previous_company_id', $before],
            ['company_id', $company->id],
            ['company_slug', $company->slug],
            ['password_changed', 'no'],
        ]);

        return self::SUCCESS;
    }
}
