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
use App\Services\Orders\OrderWorkflowService;
use App\Services\Payments\PaymentWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySeeder;
use Database\Seeders\MenuSeeder;
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
}
