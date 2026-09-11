<?php

namespace Tests\Feature;

use App\Mail\SupportTicketCreatedMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_creates_and_lists_only_own_tenant_tickets(): void
    {
        [$user, $company] = $this->manager();
        $otherUser = User::factory()->create(['company_id' => $company->id]);
        SupportTicket::query()->create(['company_id' => $company->id, 'user_id' => $otherUser->id, 'code' => 'SUP-999999', 'category' => 'other', 'subject' => 'Outro', 'description' => 'Outro ticket', 'priority' => 'normal', 'status' => 'open']);

        $response = $this->actingAs($user)->postJson('/api/app/support/tickets', [
            'company_id' => 99999,
            'category' => 'payment',
            'subject' => 'Problema ao confirmar Pix',
            'description' => 'A confirmação não foi concluída.',
            'priority' => 'high',
            'current_route' => 'pagamentos',
        ])->assertCreated()->assertJsonPath('data.category', 'payment')->assertJsonPath('data.status', 'open');

        $this->assertDatabaseHas('support_tickets', ['company_id' => $company->id, 'user_id' => $user->id, 'subject' => 'Problema ao confirmar Pix']);
        $this->actingAs($user)->getJson('/api/app/support/tickets')->assertOk()->assertJsonCount(1, 'data.tickets')
            ->assertJsonPath('data.tickets.0.id', (string) $response->json('data.id'));
    }

    public function test_validation_and_cross_tenant_related_records_are_rejected(): void
    {
        [$user, $company] = $this->manager();
        $other = Company::query()->create(['name' => 'Outra', 'slug' => 'outra']);
        $customer = Customer::query()->create(['company_id' => $other->id, 'name' => 'Cliente externo']);
        $order = $this->order($other, $customer, 'SUP-ORDER', 1);
        $base = ['category' => 'other', 'subject' => 'Ajuda', 'description' => 'Preciso de orientação.'];

        $this->actingAs($user)->postJson('/api/app/support/tickets', [...$base, 'category' => 'invalid'])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/app/support/tickets', [...$base, 'priority' => 'critical'])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/app/support/tickets', [...$base, 'related_order_id' => $order->id])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/app/support/tickets', [...$base, 'related_customer_id' => $customer->id])->assertUnprocessable();
        Auth::logout();
        $this->postJson('/api/app/support/tickets', $base)->assertUnauthorized();
    }

    public function test_email_uses_support_configuration_and_whatsapp_is_explicit_and_safe(): void
    {
        [$user] = $this->manager();
        config(['support.email' => 'support@example.test', 'support.whatsapp_number' => '5562996191921']);
        Mail::fake();

        $response = $this->actingAs($user)->postJson('/api/app/support/tickets', ['category' => 'printing', 'subject' => 'Comanda não imprime', 'description' => 'A prévia não abriu.'])->assertCreated()
            ->assertJsonPath('data.contact.whatsapp_number', '5562996191921');
        Mail::assertSent(SupportTicketCreatedMail::class, fn (SupportTicketCreatedMail $mail): bool => $mail->hasTo('support@example.test'));
        $url = (string) $response->json('data.contact.whatsapp_url');
        $this->assertStringContainsString('https://wa.me/5562996191921', $url);
        $this->assertStringNotContainsString('A prévia não abriu', $url);
    }

    public function test_mail_failure_never_discards_the_persisted_ticket_or_context_safety(): void
    {
        [$user, $company] = $this->manager();
        config(['support.email' => 'support@example.test']);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('mail unavailable'));

        $response = $this->actingAs($user)->withHeader('User-Agent', 'Browser test')->postJson('/api/app/support/tickets', ['category' => 'access', 'subject' => 'Acesso', 'description' => 'Não consigo acessar.', 'current_route' => 'perfil'])->assertCreated();
        $ticket = SupportTicket::query()->findOrFail($response->json('data.id'));
        $this->assertSame('failed', $ticket->email_delivery_status);
        $this->assertSame((string) $company->id, data_get($ticket->technical_context, 'company_id'));
        $this->assertArrayNotHasKey('token', $ticket->technical_context ?? []);
        $this->assertArrayNotHasKey('authorization', $ticket->technical_context ?? []);
    }

    /** @return array{User, Company} */
    private function manager(): array
    {
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole(Role::ADMIN_GERENTE);

        return [$user, $company];
    }

    private function order(Company $company, Customer $customer, string $code, int $sequence): Order
    {
        return Order::query()->create(['company_id' => $company->id, 'payer_customer_id' => $customer->id, 'order_date' => '2026-09-01', 'daily_sequence' => $sequence, 'code' => $code]);
    }
}
