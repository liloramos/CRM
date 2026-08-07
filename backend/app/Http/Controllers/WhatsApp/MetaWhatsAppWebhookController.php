<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhookEvent;
use App\Services\WhatsApp\WhatsAppInboundTrace;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

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

    public function receive(
        Request $request,
        WhatsAppService $whatsapp,
        WhatsAppInboundTrace $trace,
    ): JsonResponse {
        $correlationId = $trace->newCorrelationId();
        $trace->log($correlationId, 'webhook_received');

        if (! $whatsapp->signatureIsValid($request->getContent(), $request->headers->all())) {
            $trace->log($correlationId, 'signature_rejected', [
                'error_code' => 'whatsapp_signature_invalid',
            ]);

            return response()->json([
                'message' => 'Assinatura do WhatsApp invalida.',
                'code' => 'whatsapp_signature_invalid',
                'correlation_id' => $correlationId,
            ], Response::HTTP_FORBIDDEN);
        }

        $trace->log($correlationId, 'signature_validated');

        try {
            $event = $whatsapp->storeWebhookEvent(
                payload: $request->all(),
                headers: $request->headers->all(),
                method: $request->method(),
                sourceIp: $request->ip(),
                correlationId: $correlationId,
            );

            $correlationId = $trace->correlationId($event);

            if ($event->status === 'received') {
                ProcessWhatsAppWebhookEvent::dispatch($event->id)->afterResponse();
                $trace->record($event, 'job_dispatched');
            }
        } catch (Throwable $exception) {
            $trace->log($correlationId, 'event_failed', [
                'error_code' => 'whatsapp_webhook_persistence_failed',
            ]);
            report($exception);

            return response()->json([
                'message' => 'Nao foi possivel registrar o evento do WhatsApp.',
                'code' => 'whatsapp_webhook_persistence_failed',
                'correlation_id' => $correlationId,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'status' => $event->status,
            'event_id' => $event->id,
            'correlation_id' => $correlationId,
        ]);
    }
}
