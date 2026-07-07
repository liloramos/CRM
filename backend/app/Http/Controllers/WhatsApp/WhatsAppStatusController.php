<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppStatusController extends Controller
{
    public function show(Request $request, WhatsAppService $whatsapp): JsonResponse
    {
        $company = $this->companyFor($request);

        return response()->json($whatsapp->connectionStatus($company));
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
