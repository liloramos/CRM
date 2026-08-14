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

        return $orders
            ->filter(fn (Order $order): bool => $this->isActive($order))
            ->sortByDesc('id')
            ->first();
    }

    public function isActive(Order $order): bool
    {
        return ! in_array($order->status, self::INACTIVE_STATUSES, true);
    }
}
