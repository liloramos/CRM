<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Ai\AiAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAutomationStatusController extends Controller
{
    public function show(Request $request, AiAutomationService $automation): JsonResponse
    {
        $company = $this->companyFor($request);

        return response()->json($automation->connectionStatus($company));
    }

    private function companyFor(Request $request): ?Company
    {
        if ($request->user()?->company !== null) {
            return $request->user()->company;
        }

        $companyId = $request->integer('company_id');

        return $companyId > 0
            ? Company::query()->find($companyId)
            : Company::query()->orderBy('id')->first();
    }
}
