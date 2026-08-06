<?php

namespace App\Http\Controllers\Api\Champs;

use App\Champs\Enums\ChampsLeadPriority;
use App\Champs\Enums\ChampsLeadStage;
use App\Champs\Services\ChampsLeadOperationsService;
use App\Http\Controllers\Api\Champs\Concerns\ResolvesChampsCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Champs\ChampsLeadArchiveRequest;
use App\Http\Requests\Champs\UpdateChampsLeadAssignmentRequest;
use App\Http\Requests\Champs\UpdateChampsLeadFavoriteRequest;
use App\Http\Requests\Champs\UpdateChampsLeadFollowUpRequest;
use App\Http\Requests\Champs\UpdateChampsLeadPipelineRequest;
use App\Http\Resources\Champs\ChampsLeadResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ChampsLeadOperationController extends Controller
{
    use ResolvesChampsCompany;

    public function favorite(
        UpdateChampsLeadFavoriteRequest $request,
        int $lead,
        ChampsLeadOperationsService $operations,
    ): ChampsLeadResource {
        $champsLead = $this->champsLead($request, $lead);
        $actor = $this->actor($request);
        $updated = $operations->setFavorite(
            $champsLead,
            $actor,
            (bool) $request->validated('is_favorite'),
        );

        return new ChampsLeadResource($updated);
    }

    public function pipeline(
        UpdateChampsLeadPipelineRequest $request,
        int $lead,
        ChampsLeadOperationsService $operations,
    ): ChampsLeadResource {
        $validated = $request->validated();
        $stage = isset($validated['pipeline_stage'])
            ? ChampsLeadStage::from($validated['pipeline_stage'])
            : null;
        $priority = isset($validated['priority'])
            ? ChampsLeadPriority::from($validated['priority'])
            : null;

        return new ChampsLeadResource($operations->updatePipeline(
            $this->champsLead($request, $lead),
            $this->actor($request),
            $stage,
            $priority,
        ));
    }

    public function assignment(
        UpdateChampsLeadAssignmentRequest $request,
        int $lead,
        ChampsLeadOperationsService $operations,
    ): ChampsLeadResource {
        $companyId = $this->champsCompanyId($request);
        $assignedUserId = $request->validated('assigned_user_id');
        $assignee = $assignedUserId === null
            ? null
            : User::query()
                ->where('company_id', $companyId)
                ->whereKey((int) $assignedUserId)
                ->firstOrFail();

        return new ChampsLeadResource($operations->assign(
            $this->champsLead($request, $lead),
            $this->actor($request),
            $assignee,
        ));
    }

    public function followUp(
        UpdateChampsLeadFollowUpRequest $request,
        int $lead,
        ChampsLeadOperationsService $operations,
    ): ChampsLeadResource {
        $value = $request->validated('next_follow_up_at');
        $nextFollowUpAt = $value === null ? null : CarbonImmutable::parse($value);

        return new ChampsLeadResource($operations->scheduleFollowUp(
            $this->champsLead($request, $lead),
            $this->actor($request),
            $nextFollowUpAt,
        ));
    }

    public function archive(
        ChampsLeadArchiveRequest $request,
        int $lead,
        ChampsLeadOperationsService $operations,
    ): ChampsLeadResource {
        return new ChampsLeadResource($operations->archive(
            $this->champsLead($request, $lead),
            $this->actor($request),
        ));
    }

    public function restore(
        ChampsLeadArchiveRequest $request,
        int $lead,
        ChampsLeadOperationsService $operations,
    ): ChampsLeadResource {
        return new ChampsLeadResource($operations->restore(
            $this->champsLead($request, $lead),
            $this->actor($request),
        ));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        return $user;
    }
}
