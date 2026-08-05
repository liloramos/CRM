<?php

namespace App\Http\Controllers\Api\Champs;

use App\Http\Controllers\Api\Champs\Concerns\ResolvesChampsCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Champs\IndexChampsLeadRequest;
use App\Http\Resources\Champs\ChampsLeadResource;
use App\Models\ChampsLead;
use App\Models\ChampsSearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ChampsLeadController extends Controller
{
    use ResolvesChampsCompany;

    public function index(IndexChampsLeadRequest $request): AnonymousResourceCollection
    {
        $companyId = $this->champsCompanyId($request);
        $filters = $request->validated();
        $searchId = isset($filters['search_id']) ? (int) $filters['search_id'] : null;
        $direction = $filters['direction'] ?? 'desc';
        $orderBy = $filters['order_by'] ?? 'score';

        $currentScore = $this->applyResultFilters(
            ChampsSearchResult::query()
                ->select('score')
                ->whereColumn('champs_search_results.lead_id', 'champs_leads.id'),
            $companyId,
            $searchId,
            $filters,
        )
            ->latest('champs_search_results.created_at')
            ->latest('champs_search_results.id')
            ->limit(1);

        $leads = ChampsLead::query()
            ->forCompany($companyId)
            ->select('champs_leads.*')
            ->selectSub($currentScore, 'current_score')
            ->when(
                isset($filters['state']),
                fn ($query) => $query->where('state', $filters['state']),
            )
            ->when(
                isset($filters['query']) && $filters['query'] !== '',
                fn ($query) => $this->applyTextSearch($query, $filters['query']),
            );

        if ($this->hasResultFilters($searchId, $filters)) {
            $leads->whereHas(
                'searchResults',
                fn (Builder $query): Builder => $this->applyResultFilters(
                    $query,
                    $companyId,
                    $searchId,
                    $filters,
                ),
            );
        }

        match ($orderBy) {
            'name' => $leads->orderBy('name', $direction),
            'rating' => $leads->orderBy('rating', $direction),
            'created_at' => $leads->orderBy('created_at', $direction),
            default => $leads->orderBy('current_score', $direction),
        };

        $paginator = $leads
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        $this->attachCurrentResults(
            $paginator->getCollection()->all(),
            $companyId,
            $searchId,
            $filters,
        );

        return ChampsLeadResource::collection($paginator);
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
            ] as $column) {
                $query->orWhereLike($column, "%{$term}%");
            }
        });
    }

    /**
     * @param  list<ChampsLead>  $leads
     * @param  array<string, mixed>  $filters
     */
    private function attachCurrentResults(
        array $leads,
        int $companyId,
        ?int $searchId,
        array $filters,
    ): void {
        $leadIds = collect($leads)->pluck('id');

        if ($leadIds->isEmpty()) {
            return;
        }

        $results = $this->applyResultFilters(
            ChampsSearchResult::query()->whereIn('lead_id', $leadIds),
            $companyId,
            $searchId,
            $filters,
        )
            ->latest('created_at')
            ->latest('id')
            ->get()
            ->unique('lead_id')
            ->keyBy('lead_id');

        foreach ($leads as $lead) {
            $lead->setRelation('currentSearchResult', $results->get($lead->id));
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyResultFilters(
        Builder $query,
        int $companyId,
        ?int $searchId,
        array $filters,
    ): Builder {
        return $query
            ->where('champs_search_results.company_id', $companyId)
            ->when(
                $searchId !== null,
                fn ($query) => $query->where('champs_search_results.search_id', $searchId),
            )
            ->when(
                isset($filters['minimum_score']),
                fn ($query) => $query->where('champs_search_results.score', '>=', $filters['minimum_score']),
            )
            ->when(
                isset($filters['classification']),
                fn ($query) => $query->where('champs_search_results.classification', $filters['classification']),
            )
            ->when(
                array_key_exists('qualified', $filters),
                fn ($query) => $query->where('champs_search_results.qualified', (bool) $filters['qualified']),
            );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function hasResultFilters(?int $searchId, array $filters): bool
    {
        return $searchId !== null
            || isset($filters['minimum_score'])
            || isset($filters['classification'])
            || array_key_exists('qualified', $filters);
    }
}
