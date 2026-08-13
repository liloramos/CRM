<?php

namespace App\Console\Commands;

use App\Models\ConversationAlert;
use App\Models\WhatsAppMediaFile;
use Illuminate\Console\Command;

class AuditFalsePaymentProofAlertsCommand extends Command
{
    protected $signature = 'whatsapp:audit-payment-proof-alerts
        {--resolve : Resolve only alerts proven false by their linked non-proof media}
        {--confirm= : Type RESOLVER_FALSOS to resolve audited alerts}';

    protected $description = 'Lists legacy payment-proof alerts caused by unsupported media types without changing payment proofs.';

    public function handle(): int
    {
        $alerts = ConversationAlert::query()
            ->with(['messageModel.mediaFiles'])
            ->whereIn('type', ['possible_payment_proof', ConversationAlert::TYPE_PAYMENT_PROOF_RECEIVED])
            ->whereIn('status', [ConversationAlert::STATUS_OPEN, ConversationAlert::STATUS_ACKNOWLEDGED])
            ->get()
            ->filter(function (ConversationAlert $alert): bool {
                return $alert->messageModel?->mediaFiles
                    ->contains(fn (WhatsAppMediaFile $media): bool => ! in_array($media->media_type, ['image', 'document'], true)) ?? false;
            });

        $this->table(
            ['Alert ID', 'Conversa', 'Tipo alerta', 'Tipo mídia', 'Status'],
            $alerts->map(fn (ConversationAlert $alert): array => [
                $alert->id,
                $alert->conversation_id,
                $alert->type,
                $alert->messageModel?->mediaFiles->pluck('media_type')->unique()->implode(', ') ?: 'sem mídia',
                $alert->status,
            ])->all(),
        );

        if (! $this->option('resolve')) {
            $this->line('Dry run: nenhum alerta ou comprovante foi alterado.');

            return self::SUCCESS;
        }

        if ($this->laravel->environment('production') || $this->option('confirm') !== 'RESOLVER_FALSOS') {
            $this->error('A resolução exige ambiente não produtivo e --confirm=RESOLVER_FALSOS.');

            return self::FAILURE;
        }

        $alerts->each(fn (ConversationAlert $alert) => $alert->forceFill([
            'status' => ConversationAlert::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->save());

        $this->info($alerts->count().' alerta(s) falso(s) foram resolvidos. Comprovantes não foram alterados.');

        return self::SUCCESS;
    }
}
