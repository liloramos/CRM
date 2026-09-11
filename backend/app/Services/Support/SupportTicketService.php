<?php

namespace App\Services\Support;

use App\Mail\SupportTicketCreatedMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class SupportTicketService
{
    /** @param array<string, mixed> $attributes */
    public function create(Company $company, User $user, array $attributes, ?string $userAgent): SupportTicket
    {
        $orderId = $this->relatedOrderId($company, $user, $attributes['related_order_id'] ?? null);
        $customerId = $this->relatedCustomerId($company, $user, $attributes['related_customer_id'] ?? null);
        $ticket = DB::transaction(function () use ($company, $user, $attributes, $orderId, $customerId, $userAgent): SupportTicket {
            $ticket = SupportTicket::query()->create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'related_order_id' => $orderId,
                'related_customer_id' => $customerId,
                'code' => 'PENDING',
                'category' => $attributes['category'],
                'subject' => trim($attributes['subject']),
                'description' => trim($attributes['description']),
                'priority' => $attributes['priority'] ?? 'normal',
                'status' => 'open',
                'current_route' => $attributes['current_route'] ?? null,
                'technical_context' => $this->safeContext($company, $userAgent, $attributes['current_route'] ?? null),
            ]);
            $ticket->forceFill(['code' => 'SUP-'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT)])->save();

            return $ticket->refresh();
        });

        $this->notify($ticket);

        return $ticket->refresh();
    }

    /** @return array<string, mixed> */
    public function contact(SupportTicket $ticket): array
    {
        $number = config('support.whatsapp_number');
        $url = null;
        if (is_string($number) && $number !== '') {
            $message = "Olá, preciso de suporte no sistema.\n\nEmpresa: {$ticket->company->name}\nTicket: {$ticket->code}\nCategoria: {$ticket->category}\nAssunto: {$ticket->subject}";
            $url = 'https://wa.me/'.$number.'?text='.rawurlencode($message);
        }

        return [
            'email_configured' => (string) config('support.email', '') !== '',
            'whatsapp_number' => $number,
            'whatsapp_url' => $url,
        ];
    }

    private function notify(SupportTicket $ticket): void
    {
        $email = (string) config('support.email', '');
        if ($email === '') {
            return;
        }
        try {
            Mail::to($email)->send(new SupportTicketCreatedMail($ticket->loadMissing(['company', 'user'])));
            $ticket->forceFill(['email_delivery_status' => 'sent'])->save();
        } catch (\Throwable) {
            $ticket->forceFill(['email_delivery_status' => 'failed', 'email_delivery_failed_at' => now()])->save();
        }
    }

    private function relatedOrderId(Company $company, User $user, mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! $user->hasPermissionTo('orders.view') || ! Order::query()->where('company_id', $company->id)->whereKey((int) $value)->exists()) {
            throw ValidationException::withMessages(['related_order_id' => ['Pedido relacionado não está disponível.']]);
        }

        return (int) $value;
    }

    private function relatedCustomerId(Company $company, User $user, mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! $user->hasPermissionTo('customers.view') || ! Customer::query()->where('company_id', $company->id)->whereKey((int) $value)->exists()) {
            throw ValidationException::withMessages(['related_customer_id' => ['Cliente relacionado não está disponível.']]);
        }

        return (int) $value;
    }

    /** @return array<string, string|null> */
    private function safeContext(Company $company, ?string $userAgent, ?string $route): array
    {
        return [
            'app_environment' => app()->environment(),
            'company_id' => (string) $company->id,
            'current_route' => $route,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
            'reported_at' => now()->toIso8601String(),
        ];
    }
}
