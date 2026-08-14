<?php

namespace App\Services\Orders;

use App\Models\Conversation;
use App\Models\Order;

class CustomerActiveOrderResolver
{
    /** @var list<string> */
    private const INACTIVE_STATUSES = [Order::STATUS_FINISHED, Order::STATUS_CANCELLED];

    public function forConversation(Conversation $conversation): ?Order
    {
        $linked = $conversation->activeOrder;

        if ($linked instanceof Order && $this->isActive($linked) && (int) $linked->company_id === (int) $conversation->company_id) {
            return $linked;
        }

        $orders = $conversation->relationLoaded('orders')
            ? $conversation->orders
            : $conversation->orders()->latest('id')->get();

        $conversationOrder = $orders
            ->filter(fn (Order $order): bool => $this->isActive($order))
            ->sortByDesc('id')
            ->first();

        if ($conversationOrder instanceof Order) {
            return $conversationOrder;
        }

        // Manual orders are created from the Orders workspace and do not have a
        // conversation id. The customer is the stable operational link here.
        if (! $conversation->customer_id) {
            return null;
        }

        return Order::query()
            ->where('company_id', $conversation->company_id)
            ->where('payer_customer_id', $conversation->customer_id)
            ->whereNotIn('status', self::INACTIVE_STATUSES)
            ->latest('id')
            ->first();
    }

    public function isActive(Order $order): bool
    {
        return ! in_array($order->status, self::INACTIVE_STATUSES, true);
    }
}
