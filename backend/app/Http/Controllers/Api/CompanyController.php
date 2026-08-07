<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CompanyLogoRequest;
use App\Http\Requests\Settings\DestroyCompanyLogoRequest;
use App\Http\Requests\Settings\ShowCompanyRequest;
use App\Http\Requests\Settings\UpdateCompanyRequest;
use App\Http\Resources\CompanyIdentityResource;
use App\Models\Company;
use App\Services\CompanyIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function show(ShowCompanyRequest $request): JsonResponse
    {
        return $this->companyResponse($request, $this->company($request));
    }

    public function update(
        UpdateCompanyRequest $request,
        CompanyIdentityService $service,
    ): JsonResponse {
        $company = $service->update($this->company($request), $request->validated());

        return $this->companyResponse($request, $company, 'Empresa atualizada com sucesso.');
    }

    public function updateLogo(
        CompanyLogoRequest $request,
        CompanyIdentityService $service,
    ): JsonResponse {
        $logo = $request->file('logo');
        abort_unless($logo, 422);

        $company = $service->updateLogo($this->company($request), $logo);

        return $this->companyResponse($request, $company, 'Logo da empresa atualizada.');
    }

    public function destroyLogo(
        DestroyCompanyLogoRequest $request,
        CompanyIdentityService $service,
    ): JsonResponse {
        $company = $service->removeLogo($this->company($request));

        return $this->companyResponse($request, $company, 'Logo da empresa removida.');
    }

    private function company(Request $request): Company
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 404);

        return Company::query()->findOrFail($companyId);
    }

    private function companyResponse(
        Request $request,
        Company $company,
        ?string $message = null,
    ): JsonResponse {
        $payload = [
            'company' => (new CompanyIdentityResource($company))->resolve($request),
        ];

        if ($message) {
            $payload['message'] = $message;
        }

        return response()->json($payload);
    }
}
