<?php

namespace App\Services\Orders;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderFragment;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class OrderWorkflowService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createDraft(Company $company, array $attributes = []): Order
    {
        $orderDate = $this->resolveDate($attributes['order_date'] ?? null);

        return DB::transaction(function () use ($company, $attributes, $orderDate): Order {
            $dailySequence = ((int) Order::query()
                ->where('company_id', $company->id)
                ->whereDate('order_date', $orderDate->toDateString())
                ->lockForUpdate()
                ->max('daily_sequence')) + 1;

            $order = Order::query()->create([
                'company_id' => $company->id,
                'payer_customer_id' => $attributes['payer_customer_id'] ?? null,
                'conversation_id' => $attributes['conversation_id'] ?? null,
                'created_by_user_id' => $attributes['created_by_user_id'] ?? null,
                'recurring_order_reference_id' => $attributes['recurring_order_reference_id'] ?? null,
                'order_date' => $orderDate->toDateString(),
                'daily_sequence' => $dailySequence,
                'code' => $attributes['code'] ?? $this->buildCode($orderDate, $dailySequence),
                'status' => Order::STATUS_DRAFT,
                'origin_channel' => $attributes['origin_channel'] ?? Order::CHANNEL_MANUAL,
                'entry_mode' => $attributes['entry_mode'] ?? Order::CHANNEL_MANUAL,
                'fulfillment_type' => $attributes['fulfillment_type'] ?? null,
                'priority' => $attributes['priority'] ?? Order::PRIORITY_NORMAL,
                'is_manual' => $attributes['is_manual'] ?? (($attributes['origin_channel'] ?? Order::CHANNEL_MANUAL) !== Order::CHANNEL_WHATSAPP),
                'is_fragmented' => $attributes['is_fragmented'] ?? false,
                'customer_confirmation_required' => $attributes['customer_confirmation_required'] ?? true,
                'human_review_required' => $attributes['human_review_required'] ?? true,
                'recurrence_requested' => $attributes['recurrence_requested'] ?? false,
                'recurrence_note' => $attributes['recurrence_note'] ?? null,
                'general_notes' => $attributes['general_notes'] ?? null,
                'kitchen_notes' => $attributes['kitchen_notes'] ?? null,
                'pickup_person_name' => $attributes['pickup_person_name'] ?? null,
                'pickup_person_phone' => $attributes['pickup_person_phone'] ?? null,
                'pickup_authorized_by' => $attributes['pickup_authorized_by'] ?? null,
                'pickup_notes' => $attributes['pickup_notes'] ?? null,
                'currency' => $attributes['currency'] ?? 'BRL',
            ]);

            $order->statusHistories()->create([
                'from_status' => null,
                'to_status' => Order::STATUS_DRAFT,
                'user_id' => $attributes['created_by_user_id'] ?? null,
                'reason' => 'order_created',
                'notes' => $attributes['status_notes'] ?? null,
                'metadata' => ['origin_channel' => $order->origin_channel],
            ]);

            return $order;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addItem(Order $order, Product $product, array $attributes = []): OrderItem
    {
        $this->assertEditable($order);

        return DB::transaction(function () use ($order, $product, $attributes): OrderItem {
            $quantity = max(1, (int) ($attributes['quantity'] ?? 1));
            $unitPriceCents = (int) ($attributes['unit_price_cents'] ?? ($product->base_price_cents ?? 0));
            $sortOrder = (int) ($attributes['sort_order'] ?? ($order->items()->max('sort_order') + 1));

            $item = $order->items()->create([
                'product_id' => $product->id,
                'product_name' => $attributes['product_name'] ?? $product->name,
                'product_type' => $product->product_type,
                'menu_rule_code' => $product->menu_rule_code,
                'quantity' => $quantity,
                'unit_price_cents' => $unitPriceCents,
                'currency' => $attributes['currency'] ?? $product->currency,
                'item_notes' => $attributes['item_notes'] ?? null,
                'beneficiary_name' => $attributes['beneficiary_name'] ?? null,
                'beneficiary_notes' => $attributes['beneficiary_notes'] ?? null,
                'preferences' => $attributes['preferences'] ?? null,
                'restrictions' => $attributes['restrictions'] ?? null,
                'removed_ingredients' => $attributes['removed_ingredients'] ?? null,
                'selected_components' => $attributes['selected_components'] ?? null,
                'substitution_notes' => $attributes['substitution_notes'] ?? null,
                'sort_order' => $sortOrder,
            ]);

            $optionsTotal = 0;

            foreach ($attributes['options'] ?? [] as $optionRow) {
                $option = $this->resolveProductOption($optionRow);
                $optionQuantity = max(1, (int) ($optionRow['quantity'] ?? 1));
                $priceDeltaCents = (int) ($optionRow['price_delta_cents'] ?? ($option?->price_delta_cents ?? 0));
                $optionTotal = $priceDeltaCents * $optionQuantity;
                $optionsTotal += $optionTotal;

                $item->options()->create([
                    'product_option_id' => $option?->id,
                    'name' => $optionRow['name'] ?? $option?->name ?? 'Opcao do item',
                    'option_type' => $optionRow['option_type'] ?? $option?->option_type ?? ProductOption::TYPE_ADDON,
                    'group_code' => $optionRow['group_code'] ?? $option?->group_code,
                    'quantity' => $optionQuantity,
                    'price_delta_cents' => $priceDeltaCents,
                    'total_price_cents' => $optionTotal,
                    'metadata' => $optionRow['metadata'] ?? null,
                ]);
            }

            $item->forceFill([
                'options_total_cents' => $optionsTotal,
                'total_price_cents' => ($unitPriceCents * $quantity) + $optionsTotal,
            ])->save();

            $this->recalculateTotals($order);

            return $item->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addFragment(Order $order, array $attributes): OrderFragment
    {
        $this->assertEditable($order);

        return DB::transaction(function () use ($order, $attributes): OrderFragment {
            $fragment = $order->fragments()->create([
                'conversation_id' => $attributes['conversation_id'] ?? $order->conversation_id,
                'message_id' => $attributes['message_id'] ?? null,
                'created_by_user_id' => $attributes['created_by_user_id'] ?? null,
                'source_channel' => $attributes['source_channel'] ?? $order->origin_channel,
                'fragment_type' => $attributes['fragment_type'] ?? 'message',
                'content_summary' => $attributes['content_summary'] ?? null,
                'parsed_payload' => $attributes['parsed_payload'] ?? null,
                'is_resolved' => $attributes['is_resolved'] ?? false,
                'resolved_at' => $attributes['resolved_at'] ?? null,
            ]);

            if (! $order->is_fragmented) {
                $order->forceFill(['is_fragmented' => true])->save();
            }

            return $fragment;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function transitionTo(
        Order $order,
        string $status,
        ?User $user = null,
        ?string $reason = null,
        ?string $notes = null,
        array $metadata = [],
    ): Order {
        if (! in_array($status, Order::STATUSES, true)) {
            throw new DomainException("Unsupported order status [{$status}].");
        }

        return DB::transaction(function () use ($order, $status, $user, $reason, $notes, $metadata): Order {
            $fromStatus = $order->status;
            $timestampFields = $this->timestampFieldsFor($status);

            $order->forceFill([
                'status' => $status,
                ...$timestampFields,
            ]);

            if (in_array($status, Order::LOCKED_STATUSES, true) && $order->editing_locked_at === null) {
                $order->editing_locked_at = now();
                $order->editing_locked_reason = "status_{$status}";
            }

            $order->save();

            $order->statusHistories()->create([
                'user_id' => $user?->id,
                'from_status' => $fromStatus,
                'to_status' => $status,
                'reason' => $reason,
                'notes' => $notes,
                'metadata' => $metadata ?: null,
            ]);

            return $order->refresh();
        });
    }

    public function recalculateTotals(Order $order): Order
    {
        $subtotal = (int) $order->items()->sum('total_price_cents');
        $adjustments = (int) $order->adjustments_cents;

        $order->forceFill([
            'subtotal_cents' => $subtotal,
            'total_cents' => $subtotal + $adjustments,
        ])->save();

        return $order->refresh();
    }

    public function assertEditable(Order $order): void
    {
        if (! $order->canBeEdited()) {
            throw new DomainException('Order cannot be edited after printing, preparation, finalization or cancellation.');
        }
    }

    private function buildCode(CarbonInterface $date, int $dailySequence): string
    {
        return $date->format('Ymd').'-'.str_pad((string) $dailySequence, 4, '0', STR_PAD_LEFT);
    }

    private function resolveDate(mixed $date): CarbonInterface
    {
        if ($date instanceof CarbonInterface) {
            return $date;
        }

        return $date ? CarbonImmutable::parse((string) $date) : CarbonImmutable::now();
    }

    /**
     * @param  array<string, mixed>  $optionRow
     */
    private function resolveProductOption(array $optionRow): ?ProductOption
    {
        if (($optionRow['product_option'] ?? null) instanceof ProductOption) {
            return $optionRow['product_option'];
        }

        $optionId = $optionRow['product_option_id'] ?? null;

        return $optionId ? ProductOption::query()->find($optionId) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function timestampFieldsFor(string $status): array
    {
        return match ($status) {
            Order::STATUS_CONFIRMED => ['confirmed_at' => now()],
            Order::STATUS_CANCELLED => ['cancelled_at' => now()],
            Order::STATUS_FINISHED => ['finished_at' => now()],
            default => [],
        };
    }
}
