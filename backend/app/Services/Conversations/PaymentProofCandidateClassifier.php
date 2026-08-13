<?php

namespace App\Services\Conversations;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\WhatsAppMediaFile;
use Illuminate\Support\Str;

class PaymentProofCandidateClassifier
{
    /** @return array{order: Order, confidence: string, signals: list<string>}|null */
    public function classify(Conversation $conversation, Message $message, ?WhatsAppMediaFile $media): ?array
    {
        if (! $media instanceof WhatsAppMediaFile || ! in_array($media->media_type, ['image', 'document'], true)) {
            return null;
        }

        $order = $conversation->activeOrder ?: $conversation->orders()
            ->whereIn('status', [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_AWAITING_PAYMENT_PROOF])
            ->latest('id')
            ->first();

        if (! $order instanceof Order || (int) $order->total_cents <= 0) {
            return null;
        }

        $currentSignals = $this->paymentSignals((string) $message->content);
        $recentSignals = $currentSignals === [] ? $this->recentPaymentSignals($conversation, $message) : [];

        if ($currentSignals === [] && $recentSignals === []) {
            return null;
        }

        return [
            'order' => $order,
            'confidence' => $currentSignals !== [] ? 'high' : 'medium',
            'signals' => array_values(array_unique([...$currentSignals, ...$recentSignals, 'media:'.$media->media_type])),
        ];
    }

    /** @return list<string> */
    private function recentPaymentSignals(Conversation $conversation, Message $message): array
    {
        return $conversation->messages()
            ->where('id', '!=', $message->id)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->latest('id')
            ->limit(8)
            ->pluck('content')
            ->flatMap(fn (?string $content): array => $this->paymentSignals((string) $content))
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function paymentSignals(string $content): array
    {
        $normalized = Str::of($content)->ascii()->lower()->toString();
        $signals = [];

        foreach (['comprovante', 'paguei', 'pagamento', 'pix', 'transferencia', 'transferi', 'pago'] as $term) {
            if (str_contains($normalized, $term)) {
                $signals[] = 'term:'.$term;
            }
        }

        return $signals;
    }
}
