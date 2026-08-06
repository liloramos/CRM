<?php

namespace App\Http\Controllers\Api\Champs\Concerns;

use App\Models\ChampsLead;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

trait ResolvesChampsCompany
{
    protected function champsCompanyId(Request $request): int
    {
        $user = $request->user();

        abort_unless($user, Response::HTTP_UNAUTHORIZED);
        abort_if($user->company_id === null, Response::HTTP_FORBIDDEN);

        return (int) $user->company_id;
    }

    protected function champsLead(Request $request, int $leadId): ChampsLead
    {
        return ChampsLead::query()
            ->forCompany($this->champsCompanyId($request))
            ->whereKey($leadId)
            ->firstOrFail();
    }
}
