<?php

namespace App\Champs\Services;

use App\Champs\Enums\ChampsLeadPriority;
use App\Champs\Enums\ChampsLeadStage;
use App\Models\ChampsLead;
use App\Models\ChampsSearchResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ChampsSavedLeadQueryService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ChampsLead>
     */
    public function paginate(int $companyId, array $filters): LengthAwarePaginator
    {
        $direction = $filters['direction'] ?? 'desc';
        $orderBy = $filters['order_by'] ?? 'updated_at';
        $archived = $filters['archived'] ?? 'active';
        $currentScore = ChampsSearchResult::query()
            ->select('score')
            ->whereColumn('champs_search_results.lead_id', 'champs_leads.id')
            ->where('champs_search_results.company_id', $companyId)
            ->latest('champs_search_results.created_at')
            ->latest('champs_search_results.id')
            ->limit(1);

        $query = ChampsLead::query()
            ->forCompany($companyId)
            ->operational()
            ->select('champs_leads.*')
            ->selectSub($currentScore, 'current_score')
            ->with(['assignedUser:id,name', 'latestSearchResult'])
            ->withCount('activities')
            ->when($archived === 'active', fn (Builder $query) => $query->active())
            ->when($archived === 'only', fn (Builder $query) => $query->archived())
            ->when(
                isset($filters['query']) && $filters['query'] !== '',
                fn (Builder $query) => $this->applyTextSearch($query, $filters['query']),
            )
            ->when(
                isset($filters['pipeline_stage']),
                fn (Builder $query) => $query->where('pipeline_stage', $filters['pipeline_stage']),
            )
            ->when(
                isset($filters['priority']),
                fn (Builder $query) => $query->where('priority', $filters['priority']),
            )
            ->when(
                isset($filters['state']),
                fn (Builder $query) => $query->where('state', $filters['state']),
            )
            ->when(
                isset($filters['minimum_score']),
                fn (Builder $query) => $query->whereHas(
                    'latestSearchResult',
                    fn (Builder $result) => $result->where('score', '>=', $filters['minimum_score']),
                ),
            );

        $this->applyAssigneeFilter($query, $filters['assigned_user_id'] ?? null);
        $this->applyPresenceFilter($query, 'instagram_username', $filters['has_instagram'] ?? null);
        $this->applyPresenceFilter($query, 'website', $filters['has_website'] ?? null);
        $this->applyFollowUpFilter($query, $filters['follow_up'] ?? null);
        $this->applyOrdering($query, $orderBy, $direction);

        return $query
            ->orderBy('champs_leads.id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    /**
     * @return array{saved: int, interested: int, contacted: int, meetings: int, overdue: int}
     */
    public function summary(int $companyId): array
    {
        $query = ChampsLead::query()
            ->forCompany($companyId)
            ->operational()
            ->active();

        return [
            'saved' => (clone $query)->count(),
            'interested' => (clone $query)
                ->where('pipeline_stage', ChampsLeadStage::Interested->value)
                ->count(),
            'contacted' => (clone $query)
                ->where('pipeline_stage', ChampsLeadStage::Contacted->value)
                ->count(),
            'meetings' => (clone $query)
                ->where('pipeline_stage', ChampsLeadStage::MeetingScheduled->value)
                ->count(),
            'overdue' => (clone $query)
                ->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<', now())
                ->count(),
        ];
    }

    private function applyTextSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $query) use ($term): void {
            foreach ([
                'name',
                'formatted_address',
                'city',
                'state',
                'phone',
                'email',
                'website',
                'instagram_username',
                'commercial_notes',
            ] as $column) {
                $query->orWhereLike($column, "%{$term}%");
            }
        });
    }

    private function applyAssigneeFilter(Builder $query, mixed $assignee): void
    {
        if ($assignee === 'unassigned') {
            $query->whereNull('assigned_user_id');

            return;
        }

        if ($assignee !== null) {
            $query->where('assigned_user_id', (int) $assignee);
        }
    }

    private function applyPresenceFilter(Builder $query, string $column, mixed $presence): void
    {
        if ($presence === true) {
            $query->whereNotNull($column)->where($column, '!=', '');
        }

        if ($presence === false) {
            $query->where(function (Builder $query) use ($column): void {
                $query->whereNull($column)->orWhere($column, '');
            });
        }
    }

    private function applyFollowUpFilter(Builder $query, mixed $followUp): void
    {
        match ($followUp) {
            'overdue' => $query
                ->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<', now()),
            'today' => $query->whereDate('next_follow_up_at', today()),
            'upcoming' => $query->where('next_follow_up_at', '>', today()->endOfDay()),
            'none' => $query->whereNull('next_follow_up_at'),
            default => null,
        };
    }

    private function applyOrdering(Builder $query, string $orderBy, string $direction): void
    {
        match ($orderBy) {
            'name' => $query->orderBy('name', $direction),
            'score' => $query->orderBy('current_score', $direction),
            'next_follow_up_at' => $query->orderBy('next_follow_up_at', $direction),
            'priority' => $query->orderByRaw($this->priorityOrderSql($direction)),
            default => $query->orderBy('updated_at', $direction),
        };
    }

    private function priorityOrderSql(string $direction): string
    {
        $levels = [
            ChampsLeadPriority::Urgent->value => 4,
            ChampsLeadPriority::High->value => 3,
            ChampsLeadPriority::Normal->value => 2,
            ChampsLeadPriority::Low->value => 1,
        ];
        $cases = collect($levels)
            ->map(fn (int $weight, string $priority): string => "WHEN '{$priority}' THEN {$weight}")
            ->implode(' ');

        return "CASE priority {$cases} ELSE 0 END {$direction}";
    }
}
