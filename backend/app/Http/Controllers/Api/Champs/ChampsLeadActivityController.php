<?php

namespace App\Http\Controllers\Api\Champs;

use App\Champs\Enums\ChampsLeadActivityType;
use App\Champs\Services\ChampsLeadOperationsService;
use App\Http\Controllers\Api\Champs\Concerns\ResolvesChampsCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Champs\IndexChampsLeadActivityRequest;
use App\Http\Requests\Champs\StoreChampsLeadActivityRequest;
use App\Http\Resources\Champs\ChampsLeadActivityResource;
use App\Models\ChampsLeadActivity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ChampsLeadActivityController extends Controller
{
    use ResolvesChampsCompany;

    public function index(
        IndexChampsLeadActivityRequest $request,
        int $lead,
    ): AnonymousResourceCollection {
        $companyId = $this->champsCompanyId($request);
        $this->champsLead($request, $lead);

        $activities = ChampsLeadActivity::query()
            ->forCompany($companyId)
            ->where('lead_id', $lead)
            ->with('user:id,name')
            ->latest('created_at')
            ->latest('id')
            ->paginate((int) ($request->validated('per_page') ?? 50))
            ->withQueryString();

        return ChampsLeadActivityResource::collection($activities);
    }

    public function store(
        StoreChampsLeadActivityRequest $request,
        int $lead,
        ChampsLeadOperationsService $operations,
    ): JsonResponse {
        $actor = $this->actor($request);
        $validated = $request->validated();
        $occurredAt = isset($validated['occurred_at'])
            ? CarbonImmutable::parse($validated['occurred_at'])
            : null;
        $activity = $operations->addActivity(
            $this->champsLead($request, $lead),
            $actor,
            ChampsLeadActivityType::from($validated['type']),
            $validated['description'] ?? null,
            $occurredAt,
        );

        return (new ChampsLeadActivityResource($activity->load('user:id,name')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        return $user;
    }
}
