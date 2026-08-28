<?php

namespace Tests\Feature\Payments;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCreditMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\Product;
use App\Models\User;
use App\Services\Operational\OperationalCrmPresenter;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Payments\PaymentWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySeeder;
use Database\Seeders\MenuSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pix_payment_proof_waits_for_human_confirmation(): void
    {
        [$company, $customer, $order] = $this->createOrderWithProduct('n8-casa');
        $user = User::factory()->create(['company_id' => $company->id]);
        $payments = app(PaymentWorkflowService::class);

        $payment = $payments->recordPayment($order, [
            'method' => Payment::METHOD_PIX,
            'created_by_user_id' => $user->id,
        ]);

        $this->assertSame(Payment::STATUS_AWAITING_PROOF, $payment->status);
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT_PROOF, $order->refresh()->status);
        $this->assertSame(Payment::ORDER_STATUS_PENDING, $order->payment_status);

        $proof = $payments->attachProof($payment, [
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'uploaded_by_user_id' => $user->id,
            'amount_cents' => $order->total_cents,
            'review_notes' => 'Comprovante sanitizado recebido para conferencia humana.',
        ]);

        $order->refresh();

        $this->assertSame(PaymentProof::STATUS_RECEIVED, $proof->status);
        $this->assertSame(Order::STATUS_PAYMENT_PROOF_RECEIVED, $order->status);
        $this->assertSame(Payment::ORDER_STATUS_PENDING, $order->payment_status);
        $this->assertSame(0, $order->amount_paid_cents);
        $this->assertSame(1300, $order->amount_due_cents);

        $payments->confirmPayment($payment, $user);
        $order->refresh();

        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->refresh()->status);
        $this->assertSame(Order::STATUS_PAYMENT_CONFIRMED, $order->status);
        $this->assertSame(Payment::ORDER_STATUS_PAID, $order->payment_status);
        $this->assertSame(1300, $order->amount_paid_cents);
        $this->assertSame(0, $order->amount_due_cents);
        $this->assertSame($customer->id, $order->payer_customer_id);
    }

    public function test_order_confirmation_reuses_the_single_reviewable_payment_and_preserves_its_evidence(): void
    {
        [, , $order] = $this->createOrderWithProduct('n8-casa');
        $user = User::factory()->create();
        $payments = app(PaymentWorkflowService::class);
        $payment = $payments->recordPayment($order, [
            'method' => Payment::METHOD_PIX,
            'amount_cents' => $order->total_cents,
        ]);
        $rejectedProof = $payments->attachProof($payment, [
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'metadata' => ['whatsapp_media_file_id' => 501],
        ]);
        $receivedProof = $payments->attachProof($payment, [
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'metadata' => ['whatsapp_media_file_id' => 502],
        ]);
        $payments->rejectProof($rejectedProof, $user, 'Evidência inválida.');

        $confirmed = $payments->confirmOrderPayment($order, $user, [
            'method' => Payment::METHOD_PIX,
            'amount_cents' => $order->total_cents,
        ]);
        $retry = $payments->confirmOrderPayment($order, $user, [
            'method' => Payment::METHOD_PIX,
            'amount_cents' => $order->total_cents,
        ]);

        $this->assertSame($payment->id, $confirmed->id);
        $this->assertSame($payment->id, $retry->id);
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->refresh()->status);
        $this->assertSame($user->id, $payment->confirmed_by_user_id);
        $this->assertSame(PaymentProof::STATUS_REJECTED, $rejectedProof->refresh()->status);
        $this->assertSame(PaymentProof::STATUS_RECEIVED, $receivedProof->refresh()->status);
        $this->assertSame($payment->id, $receivedProof->payment_id);
    }

    public function test_order_confirmation_fails_closed_when_reviewable_payments_are_ambiguous(): void
    {
        [, , $order] = $this->createOrderWithProduct('n8-casa');
        $payments = app(PaymentWorkflowService::class);
        $payments->recordPayment($order, [
            'method' => Payment::METHOD_PIX,
            'amount_cents' => $order->total_cents,
        ]);
        Payment::query()->create([
            'company_id' => $order->company_id,
            'order_id' => $order->id,
            'customer_id' => $order->payer_customer_id,
            'method' => Payment::METHOD_CASH,
            'provider' => Payment::PROVIDER_MANUAL,
            'status' => Payment::STATUS_PENDING,
            'amount_cents' => $order->total_cents,
            'confirmed_amount_cents' => 0,
            'amount_due_after_payment_cents' => $order->total_cents,
            'currency' => $order->currency,
        ]);

        try {
            $payments->confirmOrderPayment($order, null, ['method' => Payment::METHOD_PIX]);
            $this->fail('A confirmação deveria falhar diante de cobranças abertas ambíguas.');
        } catch (DomainException $exception) {
            $this->assertSame('Existe mais de uma cobrança aberta ou uma cobrança com outra forma de pagamento. Confirme o comprovante pela conversa vinculada.', $exception->getMessage());
        }

        $this->assertSame(2, Payment::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, Payment::query()
            ->where('order_id', $order->id)
            ->where('status', Payment::STATUS_CONFIRMED)
            ->count());
    }

    public function test_whatsapp_evidence_is_idempotent_per_media_and_keeps_multiple_files_auditable(): void
    {
        [, , $order] = $this->createOrderWithProduct('n8-casa');
        $payments = app(PaymentWorkflowService::class);
        $payment = $payments->recordPayment($order, ['method' => Payment::METHOD_PIX]);

        $first = $payments->attachProof($payment, [
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'metadata' => ['whatsapp_media_file_id' => 101],
        ]);
        $retry = $payments->attachProof($payment, [
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'metadata' => ['whatsapp_media_file_id' => 101],
        ]);
        $second = $payments->attachProof($payment, [
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'metadata' => ['whatsapp_media_file_id' => 102],
        ]);

        $this->assertSame($first->id, $retry->id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $payment->proofs()->count());
        $this->assertSame(Payment::STATUS_PROOF_RECEIVED, $payment->refresh()->status);
        $this->assertSame(Order::STATUS_PAYMENT_PROOF_RECEIVED, $order->refresh()->status);
    }

    public function test_rejecting_evidence_preserves_media_and_keeps_payment_awaiting_human_review(): void
    {
        [, , $order] = $this->createOrderWithProduct('n8-casa');
        $user = User::factory()->create();
        $payments = app(PaymentWorkflowService::class);
        $payment = $payments->recordPayment($order, ['method' => Payment::METHOD_PIX]);
        $proof = $payments->attachProof($payment, [
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'metadata' => ['whatsapp_media_file_id' => 303],
        ]);

        $rejected = $payments->rejectProof($proof, $user, 'arquivo_ilegivel');

        $this->assertSame(PaymentProof::STATUS_REJECTED, $rejected->status);
        $this->assertSame(303, $rejected->metadata['whatsapp_media_file_id']);
        $this->assertSame(Payment::STATUS_AWAITING_PROOF, $payment->refresh()->status);
        $this->assertNotSame(Payment::STATUS_REJECTED, $payment->status);
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT_PROOF, $order->refresh()->status);
        $this->assertSame(0, $order->amount_paid_cents);
    }

    public function test_rejecting_one_of_multiple_evidences_does_not_reject_the_payment(): void
    {
        [, , $order] = $this->createOrderWithProduct('n8-casa');
        $user = User::factory()->create();
        $payments = app(PaymentWorkflowService::class);
        $payment = $payments->recordPayment($order, ['method' => Payment::METHOD_PIX]);
        $first = $payments->attachProof($payment, ['metadata' => ['whatsapp_media_file_id' => 401]]);
        $second = $payments->attachProof($payment, ['metadata' => ['whatsapp_media_file_id' => 402]]);

        $payments->rejectProof($first, $user, 'imagem_errada');

        $this->assertSame(Payment::STATUS_PROOF_RECEIVED, $payment->refresh()->status);
        $this->assertSame(PaymentProof::STATUS_REJECTED, $first->refresh()->status);
        $this->assertSame(PaymentProof::STATUS_RECEIVED, $second->refresh()->status);
        $this->assertSame(Order::STATUS_PAYMENT_PROOF_RECEIVED, $order->refresh()->status);
    }

    public function test_overpayment_can_generate_customer_credit_and_use_it_on_future_order(): void
    {
        [$company, $customer, $firstOrder] = $this->createOrderWithProduct('n8-casa');
        $user = User::factory()->create(['company_id' => $company->id]);
        $payments = app(PaymentWorkflowService::class);

        $payment = $payments->recordPayment($firstOrder, [
            'method' => Payment::METHOD_PIX,
            'amount_cents' => 1500,
            'created_by_user_id' => $user->id,
        ]);

        $payments->attachProof($payment, [
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'amount_cents' => 1500,
        ]);
        $payments->confirmPayment($payment, $user, [
            'overpayment_action' => Payment::OVERPAYMENT_KEEP_AS_CREDIT,
            'credit_notes' => 'Diferenca mantida como credito para uso futuro.',
        ]);

        $firstOrder->refresh();
        $customer->refresh();

        $this->assertSame(Payment::ORDER_STATUS_OVERPAID, $firstOrder->payment_status);
        $this->assertSame(1500, $firstOrder->amount_paid_cents);
        $this->assertSame(200, $firstOrder->credit_generated_cents);
        $this->assertSame(200, $customer->credit_balance_cents);
        $this->assertDatabaseHas('customer_credit_movements', [
            'customer_id' => $customer->id,
            'order_id' => $firstOrder->id,
            'payment_id' => $payment->id,
            'type' => CustomerCreditMovement::TYPE_CREDIT_GENERATED,
            'direction' => CustomerCreditMovement::DIRECTION_CREDIT,
            'amount_cents' => 200,
            'balance_after_cents' => 200,
        ]);

        $secondOrder = $this->createOrderForExistingCustomer($company, $customer, 'n5-casa');
        $creditPayment = $payments->applyCreditToOrder(
            $secondOrder,
            $customer->refresh(),
            200,
            $user,
            'Credito utilizado com confirmacao humana.',
        );

        $secondOrder->refresh();
        $customer->refresh();

        $this->assertSame(Payment::METHOD_CUSTOMER_CREDIT, $creditPayment->method);
        $this->assertSame(0, $customer->credit_balance_cents);
        $this->assertSame(200, $secondOrder->credit_used_cents);
        $this->assertSame(200, $secondOrder->amount_paid_cents);
        $this->assertSame(600, $secondOrder->amount_due_cents);
        $this->assertSame(Payment::ORDER_STATUS_PARTIAL, $secondOrder->payment_status);
        $this->assertDatabaseHas('customer_credit_movements', [
            'customer_id' => $customer->id,
            'order_id' => $secondOrder->id,
            'payment_id' => $creditPayment->id,
            'type' => CustomerCreditMovement::TYPE_CREDIT_USED,
            'direction' => CustomerCreditMovement::DIRECTION_DEBIT,
            'amount_cents' => 200,
            'balance_before_cents' => 200,
            'balance_after_cents' => 0,
        ]);
    }

    public function test_rejected_payment_keeps_reason_and_order_amount_due(): void
    {
        [$company, $customer, $order] = $this->createOrderWithProduct('n8-casa');
        $user = User::factory()->create(['company_id' => $company->id]);
        $payments = app(PaymentWorkflowService::class);

        $payment = $payments->recordPayment($order, [
            'method' => Payment::METHOD_PIX,
            'amount_cents' => 1000,
        ]);
        $payments->attachProof($payment, [
            'source_channel' => PaymentProof::SOURCE_WHATSAPP,
            'amount_cents' => 1000,
        ]);

        $payments->rejectPayment($payment, $user, 'valor_divergente', 'Valor informado nao confere com o total do pedido.');
        $order->refresh();

        $this->assertSame(Payment::STATUS_REJECTED, $payment->refresh()->status);
        $this->assertSame('valor_divergente', $payment->rejection_reason);
        $this->assertSame(Order::STATUS_PAYMENT_REJECTED, $order->status);
        $this->assertSame(Payment::ORDER_STATUS_REJECTED, $order->payment_status);
        $this->assertSame(0, $order->amount_paid_cents);
        $this->assertSame(1300, $order->amount_due_cents);
        $this->assertSame(0, $customer->refresh()->credit_balance_cents);
    }

    public function test_confirmed_payment_can_be_voided_with_auditable_reason_before_cancelling_order(): void
    {
        [$company, $customer, $order] = $this->createOrderWithProduct('n8-casa');
        $user = User::factory()->create(['company_id' => $company->id]);
        $payments = app(PaymentWorkflowService::class);

        $payment = $payments->confirmOrderPayment($order, $user, [
            'method' => Payment::METHOD_PIX,
            'amount_cents' => 1300,
        ]);

        $voided = $payments->voidLatestConfirmedPayment($order, $user, 'Conferência corrigida pela gerente.');

        $this->assertSame($payment->id, $voided->id);
        $this->assertSame(Payment::STATUS_CANCELLED, $voided->status);
        $this->assertSame($user->id, $voided->voided_by_user_id);
        $this->assertNotNull($voided->voided_at);
        $this->assertSame('Conferência corrigida pela gerente.', $voided->void_reason);
        $this->assertSame(Payment::ORDER_STATUS_UNPAID, $order->refresh()->payment_status);
        $this->assertSame(0, $order->amount_paid_cents);
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status);

        $again = $payments->voidLatestConfirmedPayment($order, $user, 'Não deve duplicar a auditoria.');
        $this->assertSame($voided->id, $again->id);
        $this->assertSame(1, $order->payments()->whereNotNull('voided_at')->count());
    }

    public function test_cancelled_unpaid_orders_are_not_financial_pending_but_paid_history_is_preserved(): void
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create(['company_id' => $company->id, 'name' => 'Cliente Financeiro']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $orders = app(OrderWorkflowService::class);
        $payments = app(PaymentWorkflowService::class);

        $active = $this->withTotal($this->createOrderForExistingCustomer($company, $customer, 'n8-casa'), 2000);
        $cancelledOne = $this->withTotal($this->createOrderForExistingCustomer($company, $customer, 'n8-casa'), 1600);
        $cancelledTwo = $this->withTotal($this->createOrderForExistingCustomer($company, $customer, 'n8-casa'), 1300);
        $orders->transitionTo($cancelledOne, Order::STATUS_CANCELLED, $user, 'cliente_desistiu');
        $orders->transitionTo($cancelledTwo, Order::STATUS_CANCELLED, $user, 'cliente_desistiu');

        $snapshot = app(OperationalCrmPresenter::class)->snapshot($company, $user);
        $entries = collect($snapshot['financeEntries'])->keyBy('orderId');

        $this->assertEquals(20, $snapshot['financialSummary']['pendingAmount']);
        $this->assertSame(1, $snapshot['financialSummary']['pendingOrders']);
        $this->assertSame('pendente', $entries[(string) $active->id]['status']);
        $this->assertEquals(20, $entries[(string) $active->id]['pendingAmount']);
        $this->assertSame('cancelado', $entries[(string) $cancelledOne->id]['status']);
        $this->assertEquals(0, $entries[(string) $cancelledOne->id]['pendingAmount']);
        $this->assertSame('Sem cobranca', $entries[(string) $cancelledOne->id]['method']);

        try {
            $payments->confirmOrderPayment($cancelledOne, $user, ['method' => Payment::METHOD_PIX]);
            $this->fail('A confirmacao de pagamento deveria ser bloqueada para pedido cancelado.');
        } catch (DomainException $exception) {
            $this->assertSame('Nao e possivel confirmar pagamento em pedido cancelado.', $exception->getMessage());
        }

        $paidThenCancelled = $this->withTotal($this->createOrderForExistingCustomer($company, $customer, 'n8-casa'), 1300);
        $payment = $payments->confirmOrderPayment($paidThenCancelled, $user, ['method' => Payment::METHOD_PIX, 'amount_cents' => 1300]);
        $orders->transitionTo($paidThenCancelled->refresh(), Order::STATUS_CANCELLED, $user, 'cancelamento_posterior_ao_pagamento');

        $snapshot = app(OperationalCrmPresenter::class)->snapshot($company, $user);
        $paidEntry = collect($snapshot['financeEntries'])->keyBy('orderId')[(string) $paidThenCancelled->id];
        $this->assertSame('pago', $paidEntry['status']);
        $this->assertEquals(13, $paidEntry['receivedAmount']);
        $this->assertEquals(0, $paidEntry['pendingAmount']);
        $this->assertEquals(13, $snapshot['financialSummary']['confirmedRevenue']);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => Payment::STATUS_CONFIRMED]);

        $voided = $payments->voidLatestConfirmedPayment($paidThenCancelled, $user, 'Estorno humano pendente de tratamento.');
        $this->assertSame(Payment::STATUS_CANCELLED, $voided->status);
        $this->assertSame(Order::STATUS_CANCELLED, $paidThenCancelled->refresh()->status);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'voided_at' => $voided->voided_at]);
    }

    /**
     * @return array{0: Company, 1: Customer, 2: Order}
     */
    private function createOrderWithProduct(string $productSlug): array
    {
        $this->seed([CompanySeeder::class, MenuSeeder::class]);

        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Pagador Sanitizado',
        ]);
        $order = $this->createOrderForExistingCustomer($company, $customer, $productSlug);

        return [$company, $customer, $order];
    }

    private function createOrderForExistingCustomer(Company $company, Customer $customer, string $productSlug): Order
    {
        $product = Product::query()->where('slug', $productSlug)->firstOrFail();
        $orders = app(OrderWorkflowService::class);
        $order = $orders->createDraft($company, [
            'payer_customer_id' => $customer->id,
            'order_date' => CarbonImmutable::create(2026, 7, 6),
        ]);

        $orders->addItem($order, $product);

        return $order->refresh();
    }

    private function withTotal(Order $order, int $totalCents): Order
    {
        $order->forceFill([
            'total_cents' => $totalCents,
            'amount_due_cents' => $totalCents,
        ])->save();

        return $order->refresh();
    }
}
