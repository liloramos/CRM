<?php

namespace App\Http\Controllers\Api\Champs;

use App\Champs\Services\ChampsSavedLeadQueryService;
use App\Http\Controllers\Api\Champs\Concerns\ResolvesChampsCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Champs\IndexSavedChampsLeadRequest;
use App\Http\Resources\Champs\ChampsLeadResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ChampsSavedLeadController extends Controller
{
    use ResolvesChampsCompany;

    public function index(
        IndexSavedChampsLeadRequest $request,
        ChampsSavedLeadQueryService $savedLeads,
    ): AnonymousResourceCollection {
        $companyId = $this->champsCompanyId($request);
        $paginator = $savedLeads->paginate($companyId, $request->validated());

        return ChampsLeadResource::collection($paginator)->additional([
            'summary' => $savedLeads->summary($companyId),
            'assignees' => User::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                ])
                ->values()
                ->all(),
        ]);
    }
}
