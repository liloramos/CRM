<?php

namespace App\Http\Controllers\Api\Champs;

use App\Champs\Exceptions\ChampsSearchException;
use App\Champs\Exceptions\LeadProviderException;
use App\Champs\Services\ChampsSearchService;
use App\Http\Controllers\Api\Champs\Concerns\ResolvesChampsCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Champs\IndexChampsSearchRequest;
use App\Http\Requests\Champs\StoreChampsSearchRequest;
use App\Http\Resources\Champs\ChampsSearchResource;
use App\Models\ChampsSearch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ChampsSearchController extends Controller
{
    use ResolvesChampsCompany;

    public function store(
        StoreChampsSearchRequest $request,
        ChampsSearchService $searches,
    ): JsonResponse {
        $validated = $request->validated();
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        try {
            $search = $searches->execute(
                user: $user,
                niche: $validated['niche'],
                city: $validated['city'],
                state: $validated['state'],
                limit: (int) $validated['limit'],
                name: $validated['name'] ?? null,
                minimumScore: (int) ($validated['minimum_score'] ?? 0),
                excludeSeen: (bool) ($validated['exclude_seen'] ?? true),
            );
        } catch (LeadProviderException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        } catch (ChampsSearchException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return (new ChampsSearchResource($search))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function index(IndexChampsSearchRequest $request): AnonymousResourceCollection
    {
        $companyId = $this->champsCompanyId($request);
        $filters = $request->validated();
        $archived = $filters['archived'] ?? 'active';

        $searches = ChampsSearch::query()
            ->forCompany($companyId)
            ->when($archived === 'active', fn ($query) => $query->active())
            ->when($archived === 'only', fn ($query) => $query->archived())
            ->when(
                isset($filters['status']),
                fn ($query) => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['state']),
                fn ($query) => $query->where('state', $filters['state']),
            )
            ->when(
                isset($filters['date_from']),
                fn ($query) => $query->whereDate('created_at', '>=', $filters['date_from']),
            )
            ->when(
                isset($filters['date_to']),
                fn ($query) => $query->whereDate('created_at', '<=', $filters['date_to']),
            )
            ->latest('created_at')
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return ChampsSearchResource::collection($searches);
    }

    public function show(Request $request, int $search): ChampsSearchResource
    {
        $companyId = $this->champsCompanyId($request);

        $champsSearch = ChampsSearch::query()
            ->forCompany($companyId)
            ->whereKey($search)
            ->with([
                'results' => fn ($query) => $query->orderBy('position')->orderBy('id'),
                'results.lead',
            ])
            ->firstOrFail();

        return new ChampsSearchResource($champsSearch);
    }

    public function archive(Request $request, int $search): ChampsSearchResource
    {
        $companyId = $this->champsCompanyId($request);
        $champsSearch = ChampsSearch::query()
            ->forCompany($companyId)
            ->whereKey($search)
            ->firstOrFail();

        $champsSearch->archive();

        return new ChampsSearchResource($champsSearch->refresh());
    }

    public function restore(Request $request, int $search): ChampsSearchResource
    {
        $companyId = $this->champsCompanyId($request);
        $champsSearch = ChampsSearch::query()
            ->forCompany($companyId)
            ->whereKey($search)
            ->firstOrFail();

        $champsSearch->restoreFromArchive();

        return new ChampsSearchResource($champsSearch->refresh());
    }

    public function archiveAll(Request $request): JsonResponse
    {
        $companyId = $this->champsCompanyId($request);
        $archivedCount = ChampsSearch::query()
            ->forCompany($companyId)
            ->active()
            ->update(['archived_at' => now()]);

        return response()->json([
            'message' => 'Histórico arquivado. Leads e memória de prospecção foram preservados.',
            'archived_count' => $archivedCount,
        ]);
    }
}
