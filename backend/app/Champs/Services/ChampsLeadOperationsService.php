<?php

namespace App\Champs\Services;

use App\Champs\Enums\ChampsLeadActivityType;
use App\Champs\Enums\ChampsLeadPriority;
use App\Champs\Enums\ChampsLeadStage;
use App\Models\ChampsLead;
use App\Models\ChampsLeadActivity;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ChampsLeadOperationsService
{
    public function setFavorite(ChampsLead $lead, User $actor, bool $isFavorite): ChampsLead
    {
        $this->ensureSameCompany($lead, $actor);

        DB::transaction(function () use ($lead, $actor, $isFavorite): void {
            if ($lead->is_favorite === $isFavorite) {
                return;
            }

            $lead->update(['is_favorite' => $isFavorite]);
            $this->recordActivity(
                $lead,
                $actor,
                $isFavorite
                    ? ChampsLeadActivityType::FavoriteAdded
                    : ChampsLeadActivityType::FavoriteRemoved,
                $isFavorite ? 'Lead adicionado aos favoritos.' : 'Lead removido dos favoritos.',
            );
        });

        return $this->freshLead($lead);
    }

    public function updatePipeline(
        ChampsLead $lead,
        User $actor,
        ?ChampsLeadStage $stage,
        ?ChampsLeadPriority $priority,
    ): ChampsLead {
        $this->ensureSameCompany($lead, $actor);

        if ($stage === null && $priority === null) {
            throw new InvalidArgumentException('Pipeline stage or priority is required.');
        }

        DB::transaction(function () use ($lead, $actor, $stage, $priority): void {
            $previousStage = $lead->pipeline_stage;
            $previousPriority = $lead->priority;
            $changes = [];

            if ($stage !== null && $stage !== $previousStage) {
                $changes['pipeline_stage'] = $stage;
            }

            if ($priority !== null && $priority !== $previousPriority) {
                $changes['priority'] = $priority;
            }

            if ($changes === []) {
                return;
            }

            $lead->update($changes);
            $this->recordActivity(
                $lead,
                $actor,
                ChampsLeadActivityType::StageChanged,
                'Pipeline comercial atualizado.',
                [
                    'previous_stage' => $previousStage->value,
                    'stage' => $lead->pipeline_stage->value,
                    'previous_priority' => $previousPriority->value,
                    'priority' => $lead->priority->value,
                ],
            );
        });

        return $this->freshLead($lead);
    }

    public function assign(ChampsLead $lead, User $actor, ?User $assignee): ChampsLead
    {
        $this->ensureSameCompany($lead, $actor);

        if ($assignee !== null && (int) $assignee->company_id !== (int) $lead->company_id) {
            throw new AuthorizationException('The assignee does not belong to this company.');
        }

        DB::transaction(function () use ($lead, $actor, $assignee): void {
            $previousAssigneeId = $lead->assigned_user_id;
            $nextAssigneeId = $assignee?->id;

            if ($previousAssigneeId === $nextAssigneeId) {
                return;
            }

            $lead->update(['assigned_user_id' => $nextAssigneeId]);
            $this->recordActivity(
                $lead,
                $actor,
                ChampsLeadActivityType::Assigned,
                $assignee === null
                    ? 'Responsável removido do lead.'
                    : "Lead atribuído a {$assignee->name}.",
                [
                    'previous_user_id' => $previousAssigneeId,
                    'assigned_user_id' => $nextAssigneeId,
                ],
            );
        });

        return $this->freshLead($lead);
    }

    public function scheduleFollowUp(
        ChampsLead $lead,
        User $actor,
        ?CarbonInterface $nextFollowUpAt,
    ): ChampsLead {
        $this->ensureSameCompany($lead, $actor);

        DB::transaction(function () use ($lead, $actor, $nextFollowUpAt): void {
            $previous = $lead->next_follow_up_at?->toIso8601String();
            $next = $nextFollowUpAt?->toIso8601String();

            if ($previous === $next) {
                return;
            }

            $lead->update(['next_follow_up_at' => $nextFollowUpAt]);
            $this->recordActivity(
                $lead,
                $actor,
                ChampsLeadActivityType::FollowUpScheduled,
                $nextFollowUpAt === null
                    ? 'Próximo acompanhamento removido.'
                    : 'Próximo acompanhamento agendado.',
                [
                    'previous_follow_up_at' => $previous,
                    'next_follow_up_at' => $next,
                ],
            );
        });

        return $this->freshLead($lead);
    }

    public function addActivity(
        ChampsLead $lead,
        User $actor,
        ChampsLeadActivityType $type,
        ?string $description = null,
        ?CarbonInterface $occurredAt = null,
    ): ChampsLeadActivity {
        $this->ensureSameCompany($lead, $actor);

        if (! in_array($type->value, ChampsLeadActivityType::userCreatableValues(), true)) {
            throw new InvalidArgumentException('This activity type cannot be created directly.');
        }

        return DB::transaction(function () use (
            $lead,
            $actor,
            $type,
            $description,
            $occurredAt,
        ): ChampsLeadActivity {
            $normalizedDescription = $this->nullableText($description);
            $metadata = null;

            if ($type === ChampsLeadActivityType::Note) {
                $lead->update(['commercial_notes' => $normalizedDescription]);
            }

            if ($type === ChampsLeadActivityType::Contacted) {
                $contactedAt = $occurredAt ?? now();
                $lead->update(['last_contacted_at' => $contactedAt]);
                $metadata = ['contacted_at' => $contactedAt->toIso8601String()];
                $normalizedDescription ??= 'Contato registrado.';
            }

            if ($type === ChampsLeadActivityType::Exported) {
                $metadata = ['format' => 'csv'];
                $normalizedDescription ??= 'Lead exportado em CSV.';
            }

            return $this->recordActivity(
                $lead,
                $actor,
                $type,
                $normalizedDescription,
                $metadata,
            );
        });
    }

    public function archive(ChampsLead $lead, User $actor): ChampsLead
    {
        $this->ensureSameCompany($lead, $actor);

        if ($lead->archived_at === null) {
            $lead->update(['archived_at' => now()]);
        }

        return $this->freshLead($lead);
    }

    public function restore(ChampsLead $lead, User $actor): ChampsLead
    {
        $this->ensureSameCompany($lead, $actor);

        if ($lead->archived_at !== null) {
            $lead->update(['archived_at' => null]);
        }

        return $this->freshLead($lead);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function recordActivity(
        ChampsLead $lead,
        User $actor,
        ChampsLeadActivityType $type,
        ?string $description,
        ?array $metadata = null,
    ): ChampsLeadActivity {
        return $lead->activities()->create([
            'company_id' => $lead->company_id,
            'user_id' => $actor->id,
            'type' => $type,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }

    private function ensureSameCompany(ChampsLead $lead, User $actor): void
    {
        if (
            $actor->company_id === null
            || (int) $lead->company_id !== (int) $actor->company_id
        ) {
            throw new AuthorizationException('The lead does not belong to this company.');
        }
    }

    private function nullableText(?string $value): ?string
    {
        $normalized = $value === null ? null : trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function freshLead(ChampsLead $lead): ChampsLead
    {
        return $lead->refresh()
            ->load(['assignedUser:id,name', 'latestSearchResult'])
            ->loadCount('activities');
    }
}
