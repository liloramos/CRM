<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\UserAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AppSessionTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_PASSWORD = 'local-session-test-password';

    public function test_phpunit_uses_the_isolated_in_memory_database(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_local_access_seeder_is_independent_from_demo_conversation_data(): void
    {
        Config::set('chatbotcrm.whatsapp.demo_data_enabled', false);

        $this->seed([
            RoleAndPermissionSeeder::class,
            UserAccessSeeder::class,
        ]);

        $admin = User::query()
            ->where('email', 'admin.gerente@example.test')
            ->firstOrFail();

        $this->assertSame('restaurante-sol', $admin->company?->slug);
        $this->assertContains(Role::ADMIN_GERENTE, $admin->roleNames());
        $this->assertNotEmpty($admin->password);
    }

    public function test_app_session_authenticates_and_logs_out_an_authorized_user(): void
    {
        $user = $this->authorizedUser();

        $this->postJson(route('api.app.session.login'), [
            'email' => $user->email,
            'password' => self::TEST_PASSWORD,
            'remember' => true,
        ])
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.company.slug', 'session-test-company');

        $this->getJson(route('api.app.session.show'))
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.email', $user->email);

        $this->postJson(route('api.app.session.logout'))
            ->assertOk()
            ->assertJsonPath('authenticated', false);

        $this->getJson(route('api.app.session.show'))
            ->assertUnauthorized()
            ->assertJsonPath('authenticated', false);
    }

    public function test_app_session_rejects_an_invalid_password(): void
    {
        $user = $this->authorizedUser();

        $this->postJson(route('api.app.session.login'), [
            'email' => $user->email,
            'password' => 'incorrect-test-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_start_or_keep_an_app_session(): void
    {
        $user = $this->authorizedUser();
        $user->update(['is_active' => false]);

        $this->postJson(route('api.app.session.login'), [
            'email' => $user->email,
            'password' => self::TEST_PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->actingAs($user)->getJson(route('api.app.session.show'))->assertUnauthorized();
        $this->actingAs($user)->getJson('/api/app/account/profile')->assertForbidden();
    }

    private function authorizedUser(): User
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $company = Company::query()->create([
            'name' => 'Session Test Company',
            'slug' => 'session-test-company',
        ]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'password' => Hash::make(self::TEST_PASSWORD),
        ]);
        $user->assignRole(Role::ADMIN_GERENTE);

        return $user;
    }
}
