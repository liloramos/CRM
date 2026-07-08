<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserAccessSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->firstOrCreate(
            ['slug' => 'restaurante-sol'],
            ['name' => 'Restaurante Sol'],
        );

        $users = [
            [
                'name' => 'Super Admin ChatBotCRM',
                'email' => 'super.admin@example.test',
                'company_id' => null,
                'role' => Role::SUPER_ADMIN,
            ],
            [
                'name' => 'Admin Gerente Demo',
                'email' => 'admin.gerente@example.test',
                'company_id' => $company->id,
                'role' => Role::ADMIN_GERENTE,
            ],
            [
                'name' => 'Atendente Demo',
                'email' => 'atendente@example.test',
                'company_id' => $company->id,
                'role' => Role::ATENDENTE,
            ],
        ];

        foreach ($users as $userData) {
            $roleName = $userData['role'];
            unset($userData['role']);

            $user = User::query()->updateOrCreate(
                ['email' => $userData['email']],
                [
                    ...$userData,
                    'email_verified_at' => now(),
                    'password' => Hash::make('password'),
                ],
            );

            $user->assignRole($roleName);
        }
    }
}
