<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Company;
use App\Models\Order;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

trait ResolvesOperationalCompany
{
    protected function resolveCompany(Request $request): Company
    {
        $user = $request->user();

        abort_unless($user, Response::HTTP_UNAUTHORIZED);

        if ($user->company_id !== null) {
            return Company::query()->whereKey($user->company_id)->firstOrFail();
        }

        return Company::query()
            ->where('slug', $request->string('company', 'restaurante-sol')->toString())
            ->firstOrFail();
    }

    protected function assertOrderBelongsToCompany(Order $order, Company $company): void
    {
        abort_unless((int) $order->company_id === (int) $company->id, Response::HTTP_NOT_FOUND);
    }
}
