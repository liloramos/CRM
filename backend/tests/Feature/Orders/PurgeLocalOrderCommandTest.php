<?php

namespace Tests\Feature\Orders;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Orders\OrderWorkflowService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeLocalOrderCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_preserves_order_and_confirmed_purge_removes_only_the_order_graph(): void
    {
        [$order, $customer, $conversation, $message] = $this->createOrderGraph();

        $this->artisan('orders:purge-local', ['code' => $order->code])
            ->expectsOutputToContain('Dry-run concluído')
            ->assertSuccessful();
        $this->assertDatabaseHas('orders', ['id' => $order->id]);

        $this->artisan('orders:purge-local', ['code' => $order->code, '--confirm' => true])
            ->expectsOutputToContain('Pedido local removido')
            ->assertSuccessful();

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('payments', ['order_id' => $order->id]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'active_order_id' => null]);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'conversation_id' => $conversation->id]);
    }

    public function test_purge_is_blocked_outside_local_or_testing(): void
    {
        config()->set('app.env', 'production');

        $this->artisan('orders:purge-local', ['code' => '20260814-0001', '--confirm' => true])
            ->expectsOutputToContain('local ou testing')
            ->assertFailed();
    }

    public function test_purge_reports_missing_order_without_writing(): void
    {
        $this->artisan('orders:purge-local', ['code' => 'inexistente', '--confirm' => true])
            ->expectsOutputToContain('Pedido não encontrado')
            ->assertFailed();
    }

    /** @return array{0: Order, 1: Customer, 2: Conversation, 3: Message} */
    private function createOrderGraph(): array
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente preservado']);
        $conversation = Conversation::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'started_at' => now()]);
        $order = app(OrderWorkflowService::class)->createDraft($company, ['payer_customer_id' => $customer->id]);
        $order->forceFill(['code' => '20260814-0001'])->save();
        $conversation->forceFill(['active_order_id' => $order->id])->save();
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'customer',
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'content' => 'Mensagem preservada.',
            'type' => 'text',
        ]);
        app(OrderWorkflowService::class)->addItem($order, Product::query()->where('slug', 'n5-casa')->firstOrFail());
        Payment::query()->create(['company_id' => $company->id, 'order_id' => $order->id, 'customer_id' => $customer->id, 'method' => Payment::METHOD_PIX, 'provider' => Payment::PROVIDER_MANUAL, 'status' => Payment::STATUS_CANCELLED, 'amount_cents' => 800, 'currency' => 'BRL', 'voided_at' => now(), 'void_reason' => 'Teste local.']);

        return [$order->refresh(), $customer, $conversation, $message];
    }
}
