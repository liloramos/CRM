<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportTicketController extends Controller
{
    use ResolvesOperationalCompany;

    public function index(Request $request, SupportTicketService $support): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $tickets = SupportTicket::query()->where('company_id', $company->id)->where('user_id', $request->user()->id)->latest()->limit(5)->get();

        return response()->json(['data' => [
            'tickets' => $tickets->map(fn (SupportTicket $ticket): array => $this->present($ticket, $support))->all(),
            'contact' => ['email_configured' => (string) config('support.email', '') !== '', 'whatsapp_number' => config('support.whatsapp_number')],
        ]]);
    }

    public function store(Request $request, SupportTicketService $support): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'subject' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
            'current_route' => ['nullable', 'string', 'max:80'],
            'related_order_id' => ['nullable', 'integer'],
            'related_customer_id' => ['nullable', 'integer'],
        ]);
        $ticket = $support->create($this->resolveCompany($request), $request->user(), $validated, $request->userAgent());

        return response()->json(['data' => $this->present($ticket, $support)], 201);
    }

    /** @return array<string, mixed> */
    private function present(SupportTicket $ticket, SupportTicketService $support): array
    {
        return [
            'id' => (string) $ticket->id,
            'code' => $ticket->code,
            'category' => $ticket->category,
            'subject' => $ticket->subject,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'email_delivery_status' => $ticket->email_delivery_status,
            'contact' => $support->contact($ticket->loadMissing('company')),
        ];
    }
}
