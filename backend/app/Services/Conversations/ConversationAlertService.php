<?php

namespace App\Services\Conversations;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConversationAlertService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function open(
        Company $company,
        string $type,
        string $severity,
        string $title,
        ?string $message = null,
        ?Conversation $conversation = null,
        ?Order $order = null,
        ?Message $messageModel = null,
        ?Payment $payment = null,
        ?PaymentProof $paymentProof = null,
        ?string $deduplicationKey = null,
        array $metadata = [],
    ): ConversationAlert {
        return DB::transaction(function () use (
            $company,
            $type,
            $severity,
            $title,
            $message,
            $conversation,
            $order,
            $messageModel,
            $payment,
            $paymentProof,
            $deduplicationKey,
            $metadata,
        ): ConversationAlert {
            $key = $deduplicationKey ?: implode(':', array_filter([
                $type,
                $conversation?->id,
                $order?->id,
                $messageModel?->id,
                $paymentProof?->id,
            ]));

            return ConversationAlert::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'deduplication_key' => $key,
                ],
                [
                    'conversation_id' => $conversation?->id,
                    'order_id' => $order?->id,
                    'message_id' => $messageModel?->id,
                    'payment_id' => $payment?->id,
                    'payment_proof_id' => $paymentProof?->id,
                    'type' => $type,
                    'severity' => $severity,
                    'title' => $title,
                    'message' => $message,
                    'status' => ConversationAlert::STATUS_OPEN,
                    'resolved_at' => null,
                    'resolved_by_user_id' => null,
                    'metadata' => $metadata,
                ],
            )->refresh();
        });
    }

    public function acknowledge(ConversationAlert $alert, ?User $user = null): ConversationAlert
    {
        if ($alert->status === ConversationAlert::STATUS_RESOLVED) {
            return $alert->refresh();
        }

        $alert->forceFill([
            'status' => ConversationAlert::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => $alert->acknowledged_at ?? now(),
            'acknowledged_by_user_id' => $alert->acknowledged_by_user_id ?? $user?->id,
        ])->save();

        return $alert->refresh();
    }

    public function resolve(ConversationAlert $alert, ?User $user = null): ConversationAlert
    {
        $alert->forceFill([
            'status' => ConversationAlert::STATUS_RESOLVED,
            'acknowledged_at' => $alert->acknowledged_at ?? now(),
            'acknowledged_by_user_id' => $alert->acknowledged_by_user_id ?? $user?->id,
            'resolved_at' => now(),
            'resolved_by_user_id' => $user?->id,
        ])->save();

        return $alert->refresh();
    }
}
