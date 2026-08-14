<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WhatsAppAudioNormalizationException;
use App\Exceptions\WhatsAppMessageSendFailedException;
use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationAlert;
use App\Models\Customer;
use App\Models\Message;
use App\Models\PaymentProof;
use App\Models\WhatsAppMediaFile;
use App\Models\WhatsAppStickerFavorite;
use App\Services\Conversations\ConversationAlertService;
use App\Services\Conversations\ConversationPresenter;
use App\Services\Conversations\ConversationWorkflowService;
use App\Services\WhatsApp\WhatsAppMediaFilename;
use App\Services\WhatsApp\WhatsAppService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ConversationOperationsController extends Controller
{
    use ResolvesOperationalCompany;

    public function index(Request $request, ConversationPresenter $presenter): JsonResponse
    {
        $company = $this->resolveCompany($request);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'mode' => ['nullable', 'string', Rule::in(['all', 'automatic', 'manual', 'attention', 'unread', 'alerts'])],
            'since' => ['nullable', 'date'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $mode = (string) ($validated['mode'] ?? 'all');

        $conversations = Conversation::query()
            ->with($this->conversationRelations())
            ->where('company_id', $company->id)
            ->when(! config('chatbotcrm.whatsapp.demo_data_enabled'), function ($query): void {
                $query->whereDoesntHave('customer', function ($customers): void {
                    $customers->where('source_channel', Customer::SOURCE_CHANNEL_DEMO)
                        ->orWhere('email', Customer::DEMO_EMAIL)
                        ->orWhere('email', 'like', Customer::DASHBOARD_DEMO_EMAIL_PREFIX.'%@example.test');
                });
            })
            ->when(isset($validated['since']), function ($query) use ($validated): void {
                $query->where(function ($nested) use ($validated): void {
                    $nested->where('updated_at', '>', $validated['since'])
                        ->orWhereHas('messages', fn ($messages) => $messages->where('updated_at', '>', $validated['since']))
                        ->orWhereHas('alerts', fn ($alerts) => $alerts->where('updated_at', '>', $validated['since']));
                });
            })
            ->when($search !== '', function ($query) use ($search): void {
                $needle = '%'.mb_strtolower($search).'%';
                $digits = preg_replace('/\D+/', '', $search) ?? '';

                $query->where(function ($nested) use ($needle, $digits): void {
                    $nested->whereRaw('LOWER(COALESCE(whatsapp_identifier, \'\')) LIKE ?', [$needle])
                        ->orWhereHas('customer', function ($customers) use ($needle, $digits): void {
                            $customers->whereRaw('LOWER(name) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$needle]);

                            if ($digits !== '') {
                                $customers->orWhereRaw(
                                    "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?",
                                    ['%'.$digits.'%'],
                                );
                            }
                        });
                });
            })
            ->when($mode === 'automatic', fn ($query) => $query->whereIn('automation_mode', [
                Conversation::AUTOMATION_MODE_ASSISTED,
                Conversation::AUTOMATION_MODE_AUTOMATIC,
            ]))
            ->when($mode === 'manual', fn ($query) => $query->where('automation_mode', Conversation::AUTOMATION_MODE_MANUAL))
            ->when($mode === 'attention', fn ($query) => $query->where('human_review_required', true))
            ->when($mode === 'unread', fn ($query) => $query->where('unread_count', '>', 0))
            ->when($mode === 'alerts', fn ($query) => $query->whereHas('alerts', fn ($alerts) => $alerts->where('status', '!=', ConversationAlert::STATUS_RESOLVED)))
            ->orderByRaw('CASE WHEN pinned_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => [
                'conversations' => $conversations->map(fn (Conversation $conversation): array => $presenter->conversation($conversation))->values(),
                'alerts' => $this->globalAlerts($company, $presenter),
            ],
            'meta' => [
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function show(Request $request, Conversation $conversation, ConversationPresenter $presenter): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $this->assertConversationBelongsToCompany($conversation, $company->id);

        return response()->json([
            'data' => $presenter->conversation($conversation->load($this->conversationRelations())),
        ]);
    }

    public function markRead(
        Request $request,
        Conversation $conversation,
        WhatsAppService $whatsapp,
        ConversationPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertConversationBelongsToCompany($conversation, $company->id);

        return response()->json([
            'data' => $presenter->conversation(
                $whatsapp->markConversationAsRead($company, $conversation)->load($this->conversationRelations()),
            ),
        ]);
    }

    public function toggleConversationPin(Request $request, Conversation $conversation, ConversationPresenter $presenter): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $this->assertConversationBelongsToCompany($conversation, $company->id);

        $conversation->forceFill([
            'pinned_at' => $conversation->pinned_at ? null : now(),
            'pinned_by_user_id' => $conversation->pinned_at ? null : $request->user()->id,
        ])->save();

        return response()->json(['data' => $presenter->conversation($conversation->load($this->conversationRelations()))]);
    }

    public function stickerFavorites(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);

        return response()->json(['data' => WhatsAppStickerFavorite::query()
            ->where('company_id', $company->id)->pluck('content_hash')->values()]);
    }

    public function toggleStickerFavorite(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $validated = $request->validate([
            'content_hash' => ['required', 'string', 'max:128'],
            'media_id' => ['nullable', 'integer'],
        ]);
        $query = WhatsAppStickerFavorite::query()->where('company_id', $company->id)->where('content_hash', $validated['content_hash']);
        $favorite = $query->first();
        if ($favorite) {
            $favorite->delete();

            return response()->json(['data' => ['favorited' => false]]);
        }
        $mediaId = $validated['media_id'] ?? null;
        if ($mediaId !== null) {
            abort_unless(WhatsAppMediaFile::query()->where('id', $mediaId)->where('company_id', $company->id)->exists(), 422);
        }
        WhatsAppStickerFavorite::query()->create(['company_id' => $company->id, 'content_hash' => $validated['content_hash'], 'whatsapp_media_file_id' => $mediaId]);

        return response()->json(['data' => ['favorited' => true]]);
    }

    public function sendMessage(
        Request $request,
        Conversation $conversation,
        ConversationWorkflowService $workflow,
        ConversationPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertConversationBelongsToCompany($conversation, $company->id);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'client_reference' => ['nullable', 'string', 'max:120'],
            'reply_to_message_id' => ['nullable', 'integer'],
        ]);

        try {
            $conversation = $workflow->sendHumanMessage(
                $company,
                $conversation,
                $request->user(),
                $validated['body'],
                $validated['client_reference'] ?? null,
                $validated['reply_to_message_id'] ?? null,
            );
        } catch (WhatsAppMessageSendFailedException $exception) {
            $conversation = Conversation::query()
                ->with($this->conversationRelations())
                ->where('company_id', $company->id)
                ->findOrFail($exception->conversationId);

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
                'data' => $presenter->conversation($conversation),
            ], 422);
        } catch (WhatsAppAudioNormalizationException $exception) {
            Log::warning('whatsapp_audio_normalization_failed', [
                'conversation_id' => $conversation->id,
                'error_code' => $exception->errorCode,
                'client_mime_type' => $request->file('file')?->getClientMimeType(),
                'detected_mime_type' => $request->file('file')?->getMimeType(),
                'size_bytes' => $request->file('file')?->getSize(),
                'recording_source' => $validated['recording_source'] ?? null,
                'recording_mime_type' => $validated['recording_mime_type'] ?? null,
                'recording_requested_mime_type' => $validated['recording_requested_mime_type'] ?? null,
            ]);

            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], 422);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $presenter->conversation($conversation->load($this->conversationRelations())),
        ]);
    }

    public function sendMediaMessage(
        Request $request,
        Conversation $conversation,
        WhatsAppService $whatsapp,
        ConversationPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertConversationBelongsToCompany($conversation, $company->id);
        $validated = $request->validate([
            'media_type' => ['required', Rule::in(['image', 'video', 'document', 'audio', 'sticker'])],
            'caption' => ['nullable', 'string', 'max:4000'],
            'file' => ['required', 'file', 'max:102400'],
            'recording_source' => ['nullable', Rule::in(['browser'])],
            'recording_mime_type' => ['nullable', 'string', 'max:100'],
            'recording_requested_mime_type' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $whatsapp->sendMediaMessage($company, $conversation, $request->file('file'), $validated['media_type'], (string) ($validated['caption'] ?? ''), [
                'sender_type' => 'human', 'sent_by_user_id' => $request->user()->id,
                'recording_source' => $validated['recording_source'] ?? null,
                'recording_mime_type' => $validated['recording_mime_type'] ?? null,
                'recording_requested_mime_type' => $validated['recording_requested_mime_type'] ?? null,
            ]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $presenter->conversation($conversation->refresh()->load($this->conversationRelations())),
        ]);
    }

    public function retryMessage(
        Request $request,
        Conversation $conversation,
        Message $message,
        ConversationWorkflowService $workflow,
        ConversationPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertConversationBelongsToCompany($conversation, $company->id);
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);

        try {
            $conversation = $workflow->retryHumanMessage($company, $conversation, $message, $request->user());
        } catch (WhatsAppMessageSendFailedException $exception) {
            $conversation = Conversation::query()
                ->with($this->conversationRelations())
                ->where('company_id', $company->id)
                ->findOrFail($exception->conversationId);

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
                'data' => $presenter->conversation($conversation),
            ], 422);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $presenter->conversation($conversation->load($this->conversationRelations())),
        ]);
    }

    public function reactToMessage(
        Request $request,
        Conversation $conversation,
        Message $message,
        WhatsAppService $whatsapp,
        ConversationPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertConversationBelongsToCompany($conversation, $company->id);
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);
        $validated = $request->validate(['emoji' => ['required', 'string', 'max:16']]);

        try {
            $conversation = $whatsapp->sendReaction($company, $conversation, $message, $validated['emoji'], $request->user()->id);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $presenter->conversation($conversation->load($this->conversationRelations()))]);
    }

    public function togglePin(
        Request $request,
        Conversation $conversation,
        Message $message,
        ConversationPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertConversationBelongsToCompany($conversation, $company->id);
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);

        $message->forceFill([
            'pinned_at' => $message->pinned_at ? null : now(),
            'pinned_by_user_id' => $message->pinned_at ? null : $request->user()->id,
        ])->save();

        return response()->json([
            'data' => $presenter->conversation($conversation->load($this->conversationRelations())),
        ]);
    }

    public function hideMessage(Request $request, Conversation $conversation, Message $message, ConversationPresenter $presenter): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $this->assertConversationBelongsToCompany($conversation, $company->id);
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);

        $message->forceFill([
            'hidden_at' => $message->hidden_at ? null : now(),
            'hidden_by_user_id' => $message->hidden_at ? null : $request->user()->id,
        ])->save();

        return response()->json(['data' => $presenter->conversation($conversation->load($this->conversationRelations()))]);
    }

    public function setMode(
        Request $request,
        Conversation $conversation,
        ConversationWorkflowService $workflow,
        ConversationPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertConversationBelongsToCompany($conversation, $company->id);

        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::in([Conversation::AUTOMATION_MODE_ASSISTED, Conversation::AUTOMATION_MODE_AUTOMATIC, Conversation::AUTOMATION_MODE_MANUAL])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $conversation = $workflow->switchMode($conversation, $validated['mode'], $request->user(), $validated['reason'] ?? null);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $presenter->conversation($conversation->load($this->conversationRelations())),
        ]);
    }

    public function acknowledgeAlert(
        Request $request,
        Conversation $conversation,
        ConversationAlert $alert,
        ConversationAlertService $alerts,
        ConversationPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertConversationBelongsToCompany($conversation, $company->id);
        abort_unless((int) $alert->company_id === (int) $company->id && (int) $alert->conversation_id === (int) $conversation->id, 404);

        return response()->json([
            'data' => $presenter->alert($alerts->acknowledge($alert, $request->user())),
        ]);
    }

    public function resolveAlert(
        Request $request,
        Conversation $conversation,
        ConversationAlert $alert,
        ConversationAlertService $alerts,
        ConversationPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertConversationBelongsToCompany($conversation, $company->id);
        abort_unless((int) $alert->company_id === (int) $company->id && (int) $alert->conversation_id === (int) $conversation->id, 404);

        return response()->json([
            'data' => $presenter->alert($alerts->resolve($alert, $request->user())),
        ]);
    }

    public function approvePaymentProof(
        Request $request,
        Conversation $conversation,
        PaymentProof $proof,
        ConversationWorkflowService $workflow,
        ConversationPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertConversationBelongsToCompany($conversation, $company->id);

        $validated = $request->validate([
            'confirmed_amount_cents' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $conversation = $workflow->approvePaymentProof(
                $company,
                $conversation,
                $proof,
                $request->user(),
                (int) $validated['confirmed_amount_cents'],
                $validated['notes'] ?? null,
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $presenter->conversation($conversation->load($this->conversationRelations())),
        ]);
    }

    public function rejectPaymentProof(
        Request $request,
        Conversation $conversation,
        PaymentProof $proof,
        ConversationWorkflowService $workflow,
        ConversationPresenter $presenter,
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $this->assertConversationBelongsToCompany($conversation, $company->id);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $conversation = $workflow->rejectPaymentProof($company, $conversation, $proof, $request->user(), $validated['reason']);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $presenter->conversation($conversation->load($this->conversationRelations())),
        ]);
    }

    public function showMedia(Request $request, WhatsAppMediaFile $media)
    {
        $company = $this->resolveCompany($request);
        abort_unless((int) $media->company_id === (int) $company->id, 404);
        abort_unless($media->storage_disk && $media->file_path && Storage::disk($media->storage_disk)->exists($media->file_path), 404);

        $filename = WhatsAppMediaFilename::forMedia($media->original_filename, $media->mime_type, $media->media_type, $media->created_at?->format('Ymd-His'), $media->id);
        $disk = Storage::disk($media->storage_disk);

        return Response::make($disk->get($media->file_path), 200, [
            'Content-Type' => $media->mime_type ?: 'application/octet-stream',
            'Content-Length' => (string) $disk->size($media->file_path),
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    public function downloadMedia(Request $request, Conversation $conversation, WhatsAppMediaFile $media)
    {
        $company = $this->resolveCompany($request);
        $this->assertConversationBelongsToCompany($conversation, $company->id);
        abort_unless((int) $media->company_id === $company->id, 404);
        abort_unless((int) $media->message()->value('conversation_id') === (int) $conversation->id, 404);
        abort_unless($media->storage_disk && $media->file_path && Storage::disk($media->storage_disk)->exists($media->file_path), 404);

        $filename = WhatsAppMediaFilename::forMedia($media->original_filename, $media->mime_type, $media->media_type, $media->created_at?->format('Ymd-His'), $media->id);
        $disk = Storage::disk($media->storage_disk);

        return Response::make($disk->get($media->file_path), 200, [
            'Content-Type' => $media->mime_type ?: 'application/octet-stream',
            'Content-Length' => (string) $disk->size($media->file_path),
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    private function assertConversationBelongsToCompany(Conversation $conversation, int $companyId): void
    {
        abort_unless((int) $conversation->company_id === $companyId, 404);
    }

    /**
     * @return list<string>
     */
    private function conversationRelations(): array
    {
        return [
            'customer.addresses',
            'assignedUser',
            'pinnedBy',
            'manualTakeoverBy',
            'activeOrder.payerCustomer',
            'activeOrder.items.options',
            'activeOrder.statusHistories',
            'activeOrder.latestPrintJob',
            'activeOrder.payments.proofs',
            'messages.mediaFiles',
            'messages.whatsappMessageDeliveries',
            'messages.replyTo',
            'messages.pinnedBy',
            'alerts.payment',
            'alerts.paymentProof',
            'orders' => fn ($query) => $query->latest('id')->limit(1),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function globalAlerts($company, ConversationPresenter $presenter): array
    {
        return ConversationAlert::query()
            ->where('company_id', $company->id)
            ->where('status', '!=', ConversationAlert::STATUS_RESOLVED)
            ->where('type', '!=', ConversationAlert::TYPE_UNREAD_MESSAGE)
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (ConversationAlert $alert): array => $presenter->alert($alert))
            ->values()
            ->all();
    }
}
