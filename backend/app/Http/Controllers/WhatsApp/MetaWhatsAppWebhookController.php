<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhookEvent;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MetaWhatsAppWebhookController extends Controller
{
    public function verify(Request $request, WhatsAppService $whatsapp): Response
    {
        $challenge = $whatsapp->verifyWebhook(
            $request->query('hub.mode') ?? $request->query('hub_mode'),
            $request->query('hub.verify_token') ?? $request->query('hub_verify_token'),
            $request->query('hub.challenge') ?? $request->query('hub_challenge'),
        );

        abort_if($challenge === null, Response::HTTP_FORBIDDEN);

        return response($challenge, Response::HTTP_OK)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function receive(Request $request, WhatsAppService $whatsapp): JsonResponse
    {
        if (! $whatsapp->signatureIsValid($request->getContent(), $request->headers->all())) {
            return response()->json([
                'message' => 'Assinatura do WhatsApp invalida.',
            ], Response::HTTP_FORBIDDEN);
        }

        $event = $whatsapp->storeWebhookEvent(
            payload: $request->all(),
            headers: $request->headers->all(),
            method: $request->method(),
            sourceIp: $request->ip(),
        );

        if ($event->status === 'received') {
            ProcessWhatsAppWebhookEvent::dispatch($event->id)->afterResponse();
        }

        return response()->json([
            'status' => $event->status,
            'event_id' => $event->id,
        ]);
    }
}
