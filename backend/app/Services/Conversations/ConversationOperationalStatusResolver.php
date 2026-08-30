<?php

namespace App\Services\Conversations;

use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Order;
use App\Models\Payment;

class ConversationOperationalStatusResolver
{
    /**
     * @return array{code: string, label: string, tone: string, reason: ?string, priority: int}
     */
    public function resolve(Conversation $conversation, ?Order $order = null): array
    {
        $activeAlerts = collect($conversation->alerts ?? [])
            ->filter(fn (ConversationAlert $alert): bool => $alert->isCurrentActionable());

        $reviewRequiredForOperationalReason = (bool) $conversation->human_review_required
            && ! ($conversation->automation_mode === Conversation::AUTOMATION_MODE_MANUAL
                && $conversation->automation_status === Conversation::AUTOMATION_STATUS_MANUAL_TAKEOVER);

        if ($reviewRequiredForOperationalReason || $activeAlerts->contains(
            fn (ConversationAlert $alert): bool => $alert->severity === ConversationAlert::SEVERITY_CRITICAL
                || $alert->type === ConversationAlert::TYPE_MESSAGE_SEND_FAILED
                || $alert->type === ConversationAlert::TYPE_PAYMENT_REJECTED,
        ) || ($order?->payment_status === Payment::ORDER_STATUS_REJECTED)) {
            $alert = $activeAlerts->first(fn (ConversationAlert $candidate): bool => $candidate->severity === ConversationAlert::SEVERITY_CRITICAL
                || $candidate->type === ConversationAlert::TYPE_MESSAGE_SEND_FAILED
                || $candidate->type === ConversationAlert::TYPE_PAYMENT_REJECTED);

            return $this->status('ATTENTION', 'Atenção', 'red', $alert?->message ?: ($conversation->handoff_reason ?: 'É necessária uma ação humana.'), 100);
        }

        if (! $order instanceof Order) {
            return $this->status('IDLE', 'Sem pedido em andamento', 'gray', null, 0);
        }

        if ($this->isCompleted($order)) {
            return $this->status('COMPLETED', 'Concluído', 'green', 'Fluxo concluído normalmente.', 80);
        }

        if ($this->isOutForDelivery($order)) {
            return $this->status('OUT_FOR_DELIVERY', 'Saiu para entrega', 'teal', 'Pedido em rota de entrega.', 70);
        }

        if ($this->isAwaitingDelivery($order)) {
            $isPickup = $order->fulfillment_type !== Order::FULFILLMENT_DELIVERY;

            return $this->status(
                'AWAITING_DELIVERY',
                $isPickup ? 'Aguardando retirada' : 'Aguardando entrega',
                'purple',
                $isPickup ? 'Pedido pronto aguardando retirada.' : 'Pedido pronto aguardando saída para entrega.',
                60,
            );
        }

        if ($this->isPreparing($order)) {
            return $this->status('PREPARING', 'Em preparo', 'cyan', 'Restaurante preparando o pedido.', 50);
        }

        if ($this->isPaymentReview($order)) {
            return $this->status('PAYMENT_REVIEW', 'Pagamento em análise', 'orange', 'Comprovante aguardando confirmação humana.', 40);
        }

        if ($this->isAwaitingPayment($order)) {
            return $this->status('AWAITING_PAYMENT', 'Aguardando pagamento', 'yellow', 'Esperando confirmação do pagamento.', 30);
        }

        return $this->status('IN_SERVICE', 'Em atendimento', 'blue', 'Pedido sendo montado ou aguardando informações.', 20);
    }

    /** @return array{code: string, label: string, tone: string, reason: ?string, priority: int} */
    private function status(string $code, string $label, string $tone, ?string $reason, int $priority): array
    {
        return compact('code', 'label', 'tone', 'reason', 'priority');
    }

    private function isCompleted(Order $order): bool
    {
        return in_array($order->status, [Order::STATUS_FINISHED], true)
            || in_array($order->delivery_status, [Order::DELIVERY_STATUS_DELIVERED], true)
            || in_array($order->pickup_status, [Order::PICKUP_STATUS_PICKED_UP], true);
    }

    private function isOutForDelivery(Order $order): bool
    {
        return $order->status === Order::STATUS_OUT_FOR_DELIVERY
            || $order->delivery_status === Order::DELIVERY_STATUS_OUT_FOR_DELIVERY;
    }

    private function isAwaitingDelivery(Order $order): bool
    {
        return in_array($order->status, [Order::STATUS_READY_FOR_PICKUP], true)
            || in_array($order->pickup_status, [Order::PICKUP_STATUS_READY], true)
            || $order->delivery_status === Order::DELIVERY_STATUS_QUOTED;
    }

    private function isPreparing(Order $order): bool
    {
        return in_array($order->status, [
            Order::STATUS_PAYMENT_CONFIRMED,
            Order::STATUS_READY_TO_PRINT,
            Order::STATUS_PRINTED,
            Order::STATUS_IN_PREPARATION,
        ], true);
    }

    private function isPaymentReview(Order $order): bool
    {
        if (in_array($order->status, [Order::STATUS_PAYMENT_PROOF_RECEIVED], true)) {
            return true;
        }

        return $order->payments
            ->contains(fn (Payment $payment): bool => $payment->status === Payment::STATUS_PROOF_RECEIVED);
    }

    private function isAwaitingPayment(Order $order): bool
    {
        if (in_array($order->status, [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_AWAITING_PAYMENT_PROOF], true)) {
            return true;
        }

        return $order->payment_status !== Payment::ORDER_STATUS_PAID
            && $order->payment_status !== Payment::ORDER_STATUS_OVERPAID
            && in_array($order->status, [Order::STATUS_CONFIRMED], true);
    }
}
