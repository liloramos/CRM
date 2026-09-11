<?php

namespace Tests\Feature\Operational;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\PrintJob;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionOperationalPolishTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CompanySeeder::class, RoleAndPermissionSeeder::class]);
        $this->company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-08-30 12:00:00', 'America/Sao_Paulo'));
    }

    public function test_manager_can_delete_customer_without_protected_history(): void
    {
        $manager = $this->userWithRole(Role::ADMIN_GERENTE);
        $customer = Customer::query()->create(['company_id' => $this->company->id, 'name' => 'Cadastro de teste']);
        CustomerAddress::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'label' => 'Principal',
            'street' => 'Rua Teste',
            'country_code' => 'BR',
        ]);

        $this->actingAs($manager)
            ->deleteJson("/api/app/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('customer_addresses', ['customer_id' => $customer->id]);
    }

    public function test_customer_with_order_history_is_preserved_and_attendant_cannot_delete_customers(): void
    {
        $manager = $this->userWithRole(Role::ADMIN_GERENTE);
        $attendant = $this->userWithRole(Role::ATENDENTE);
        $customer = Customer::query()->create(['company_id' => $this->company->id, 'name' => 'Cliente com historico']);
        $this->order($customer, 'SOL-001');

        $this->actingAs($manager)
            ->deleteJson("/api/app/customers/{$customer->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'customer_has_protected_history')
            ->assertJsonPath('data.eligible', false);

        $this->actingAs($attendant)
            ->deleteJson("/api/app/customers/{$customer->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Cliente com historico']);
        $this->assertDatabaseHas('orders', ['payer_customer_id' => $customer->id]);
    }

    public function test_financial_overview_uses_real_payments_and_is_restricted_to_management(): void
    {
        $manager = $this->userWithRole(Role::ADMIN_GERENTE);
        $attendant = $this->userWithRole(Role::ATENDENTE);
        $customer = Customer::query()->create(['company_id' => $this->company->id, 'name' => 'Cliente Financeiro']);
        $order = $this->order($customer, 'SOL-002');

        $confirmed = $this->payment($order, $customer, Payment::STATUS_CONFIRMED, 2000, ['confirmed_amount_cents' => 2000, 'confirmed_at' => now(), 'confirmed_by_user_id' => $manager->id]);
        $this->payment($order, $customer, Payment::STATUS_PENDING, 1000);
        $this->payment($order, $customer, Payment::STATUS_CANCELLED, 500, ['confirmed_amount_cents' => 500, 'voided_at' => now(), 'voided_by_user_id' => $manager->id]);

        $response = $this->actingAs($manager)
            ->getJson('/api/app/financial-overview?from=2026-08-30&to=2026-08-30')
            ->assertOk();

        $response->assertJsonPath('data.summary.confirmedRevenue', 20)
            ->assertJsonPath('data.summary.pendingAmount', 10)
            ->assertJsonPath('data.summary.voidedAmount', 5)
            ->assertJsonPath('data.summary.voidedCount', 1)
            ->assertJsonCount(1, 'data.movements');

        $this->actingAs($manager)
            ->getJson('/api/app/financial-overview?from=2026-08-30&to=2026-08-30&status=pending')
            ->assertOk()
            ->assertJsonPath('data.summary.confirmedRevenue', 0)
            ->assertJsonPath('data.summary.pendingAmount', 10)
            ->assertJsonCount(1, 'data.movements');

        $this->actingAs($attendant)
            ->getJson('/api/app/financial-overview?from=2026-08-30&to=2026-08-30')
            ->assertForbidden();

        $this->actingAs($attendant)
            ->postJson("/api/app/orders/{$order->id}/payments/void", ['reason' => 'Tentativa sem autoridade'])
            ->assertForbidden();

        $this->actingAs($manager)
            ->postJson("/api/app/orders/{$order->id}/payments/void", ['reason' => 'Correcao administrativa auditada'])
            ->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $confirmed->id,
            'status' => Payment::STATUS_CANCELLED,
            'voided_by_user_id' => $manager->id,
            'void_reason' => 'Correcao administrativa auditada',
        ]);

        $this->actingAs($manager)
            ->postJson("/api/app/orders/{$order->id}/payments/{$confirmed->id}/void", ['reason' => 'Repeticao controlada'])
            ->assertUnprocessable();
    }

    public function test_financial_overview_consolidates_split_order_payments_into_one_sale(): void
    {
        $manager = $this->userWithRole(Role::ADMIN_GERENTE);
        $customer = Customer::query()->create(['company_id' => $this->company->id, 'name' => 'Murilo Ramos']);
        $order = $this->order($customer, '20260831-0001');
        $order->forceFill([
            'subtotal_cents' => 800,
            'delivery_fee_cents' => 801,
            'adjustments_cents' => 0,
            'total_cents' => 1601,
            'amount_paid_cents' => 800,
            'amount_due_cents' => 801,
            'payment_method' => Payment::METHOD_PIX,
            'payment_status' => Payment::ORDER_STATUS_PAID,
        ])->save();
        $this->payment($order, $customer, Payment::STATUS_CONFIRMED, 800, ['confirmed_amount_cents' => 800, 'confirmed_at' => now(), 'confirmed_by_user_id' => $manager->id]);
        $this->payment($order, $customer, Payment::STATUS_CANCELLED, 801, ['confirmed_amount_cents' => 801, 'voided_at' => now()->addSeconds(18), 'voided_by_user_id' => $manager->id]);
        $response = $this->actingAs($manager)
            ->getJson('/api/app/financial-overview?from=2026-08-30&to=2026-08-30')
            ->assertOk();

        $response->assertJsonPath('data.summary.confirmedRevenue', 8)
            ->assertJsonPath('data.summary.voidedAmount', 8.01)
            ->assertJsonPath('data.summary.averageTicket', 8)
            ->assertJsonCount(1, 'data.movements')
            ->assertJsonPath('data.movements.0.orderCode', '20260831-0001')
            ->assertJsonPath('data.movements.0.status', 'partially_voided')
            ->assertJsonPath('data.movements.0.amount', 8)
            ->assertJsonPath('data.movements.0.totalAmount', 16.01)
            ->assertJsonPath('data.movements.0.paymentCount', 2)
            ->assertJsonPath('data.movements.0.details.items', 8)
            ->assertJsonPath('data.movements.0.details.deliveryFee', 8.01)
            ->assertJsonPath('data.movements.0.details.total', 16.01)
            ->assertJsonPath('data.movements.0.details.received', 8)
            ->assertJsonCount(2, 'data.movements.0.details.payments');
    }

    public function test_reports_calculate_canonical_metrics_and_empty_history_is_not_an_active_alert(): void
    {
        $manager = $this->userWithRole(Role::ADMIN_GERENTE);
        $customer = Customer::query()->create(['company_id' => $this->company->id, 'name' => 'Cliente Relatorio']);
        $order = $this->order($customer, 'SOL-003', Order::STATUS_FINISHED);
        $oldOrder = $this->order($customer, 'SOL-004', Order::STATUS_FINISHED);
        $oldOrder->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])->save();
        $this->payment($order, $customer, Payment::STATUS_CONFIRMED, 3500, ['confirmed_amount_cents' => 3500, 'confirmed_at' => now(), 'confirmed_by_user_id' => $manager->id]);

        $conversation = Conversation::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC,
            'started_at' => now(),
        ]);
        $this->message($conversation, 'customer', 'inbound', now()->subMinutes(4));
        $this->message($conversation, 'agent', 'outbound', now()->subMinutes(2));
        PrintJob::query()->create([
            'company_id' => $this->company->id,
            'order_id' => $order->id,
            'status' => PrintJob::STATUS_FAILED,
            'job_type' => PrintJob::TYPE_ORDER_TICKET,
        ]);
        ConversationAlert::query()->create([
            'company_id' => $this->company->id,
            'conversation_id' => $conversation->id,
            'type' => ConversationAlert::TYPE_HUMAN_REQUESTED,
            'severity' => ConversationAlert::SEVERITY_WARNING,
            'title' => 'Revisao humana',
            'status' => ConversationAlert::STATUS_RESOLVED,
        ]);

        $response = $this->actingAs($manager)
            ->getJson('/api/app/reports/operational?from=2026-08-30&to=2026-08-30')
            ->assertOk();

        $response->assertJsonPath('data.metrics.averageResponseSeconds', 120)
            ->assertJsonPath('data.metrics.ordersCreated', 1)
            ->assertJsonPath('data.metrics.ordersPaid', 1)
            ->assertJsonPath('data.metrics.confirmedRevenue', 35)
            ->assertJsonPath('data.metrics.printFailures', 1)
            ->assertJsonPath('data.metrics.humanReviewEvents', 1)
            ->assertJsonPath('data.sufficiency.responseTime', true);

        $this->actingAs($manager)
            ->getJson('/api/app/conversations')
            ->assertOk()
            ->assertJsonCount(0, 'data.alerts')
            ->assertJsonPath('data.conversations.0.alerts.0.isActionable', false);

        $attendant = $this->userWithRole(Role::ATENDENTE);
        $this->actingAs($attendant)
            ->getJson('/api/app/reports/operational?from=2026-08-30&to=2026-08-30')
            ->assertForbidden();
    }

    public function test_empty_report_returns_insufficient_data_instead_of_invented_metrics(): void
    {
        $manager = $this->userWithRole(Role::ADMIN_GERENTE);

        $this->actingAs($manager)
            ->getJson('/api/app/reports/operational?from=2026-08-30&to=2026-08-30')
            ->assertOk()
            ->assertJsonPath('data.metrics.averageResponseSeconds', null)
            ->assertJsonPath('data.metrics.ordersCreated', 0)
            ->assertJsonPath('data.metrics.confirmedRevenue', 0)
            ->assertJsonPath('data.sufficiency.responseTime', false)
            ->assertJsonPath('data.sufficiency.orders', false)
            ->assertJsonPath('data.sufficiency.payments', false)
            ->assertJsonPath('data.sufficiency.printing', false);
    }

    public function test_payment_queue_projects_exact_order_customer_and_proof_without_parallel_financial_state(): void
    {
        $manager = $this->userWithRole(Role::ADMIN_GERENTE);
        $customer = Customer::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Cliente Pix Identificado',
            'phone' => '5562999990000',
        ]);
        $conversation = Conversation::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'automation_mode' => Conversation::AUTOMATION_MODE_MANUAL,
            'started_at' => now(),
        ]);
        $order = $this->order($customer, 'SOL-PIX-10');
        $order->forceFill([
            'conversation_id' => $conversation->id,
            'customer_phone_snapshot' => $customer->phone,
            'payment_method' => Payment::METHOD_PIX,
            'payment_status' => Payment::ORDER_STATUS_PENDING,
        ])->save();
        $payment = $this->payment($order, $customer, Payment::STATUS_PROOF_RECEIVED, 3500);
        $proof = PaymentProof::query()->create([
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'status' => PaymentProof::STATUS_RECEIVED,
            'amount_cents' => 3500,
            'received_at' => now(),
            'original_filename' => 'comprovante.jpg',
            'mime_type' => 'image/jpeg',
            'metadata' => ['whatsapp_media_file_id' => 321],
        ]);

        $response = $this->actingAs($manager)
            ->getJson('/api/app/operational-snapshot')
            ->assertOk();

        $response->assertJsonPath('data.financeEntries.0.orderId', (string) $order->id)
            ->assertJsonPath('data.financeEntries.0.orderCode', 'SOL-PIX-10')
            ->assertJsonPath('data.financeEntries.0.customerName', 'Cliente Pix Identificado')
            ->assertJsonPath('data.financeEntries.0.customerPhone', '5562999990000')
            ->assertJsonPath('data.financeEntries.0.conversationId', (string) $conversation->id)
            ->assertJsonPath('data.financeEntries.0.proof.id', (string) $proof->id)
            ->assertJsonPath('data.financeEntries.0.proof.status', PaymentProof::STATUS_RECEIVED)
            ->assertJsonPath('data.financeEntries.0.proof.mediaUrl', '/api/app/conversations/media/321')
            ->assertJsonPath('data.financeEntries.0.canReviewProof', true)
            ->assertJsonPath('data.financeEntries.0.canConfirmPayment', true);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $user->assignRole($role);

        return $user;
    }

    private function order(Customer $customer, string $code, string $status = Order::STATUS_AWAITING_PAYMENT): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'payer_customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'order_date' => now()->toDateString(),
            'daily_sequence' => (int) preg_replace('/\D/', '', $code),
            'code' => $code,
            'status' => $status,
            'origin_channel' => Order::CHANNEL_MANUAL,
            'entry_mode' => 'manual',
            'total_cents' => 3500,
            'amount_due_cents' => 3500,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function payment(Order $order, Customer $customer, string $status, int $amount, array $extra = []): Payment
    {
        return Payment::query()->create([
            'company_id' => $this->company->id,
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'method' => Payment::METHOD_PIX,
            'status' => $status,
            'amount_cents' => $amount,
            ...$extra,
        ]);
    }

    private function message(Conversation $conversation, string $sender, string $direction, $createdAt): Message
    {
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => $sender,
            'direction' => $direction,
            'content' => 'Mensagem de teste',
            'type' => 'text',
        ]);
        $message->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $message;
    }
}
