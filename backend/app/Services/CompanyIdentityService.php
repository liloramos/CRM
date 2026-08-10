<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CompanyIdentityService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Company $company, array $attributes): Company
    {
        return DB::transaction(function () use ($company, $attributes): Company {
            $company->fill(Arr::only($attributes, [
                'name',
                'trade_name',
                'responsible_name',
                'email',
                'phone',
            ]));
            $company->save();

            if (array_key_exists('timezone', $attributes)) {
                $company->setting()->updateOrCreate([], [
                    'timezone' => $attributes['timezone'],
                ]);
            }

            return $company->fresh(['setting']);
        });
    }

    public function updateLogo(Company $company, UploadedFile $logo): Company
    {
        $path = $logo->storePublicly("companies/{$company->id}/logo", config('filesystems.default'));

        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('Não foi possível armazenar a logo da empresa.');
        }

        $previousPath = $company->logo_path;

        try {
            DB::transaction(function () use ($company, $path): void {
                $company->forceFill(['logo_path' => $path])->save();
            });
        } catch (Throwable $exception) {
            Storage::disk(config('filesystems.default'))->delete($path);

            throw $exception;
        }

        $this->deleteOwnedLogo($company, $previousPath);

        return $company->fresh(['setting']);
    }

    public function removeLogo(Company $company): Company
    {
        $previousPath = $company->logo_path;

        DB::transaction(function () use ($company): void {
            $company->forceFill(['logo_path' => null])->save();
        });

        $this->deleteOwnedLogo($company, $previousPath);

        return $company->fresh(['setting']);
    }

    private function deleteOwnedLogo(Company $company, ?string $path): void
    {
        if (! $path || Str::contains($path, '..')) {
            return;
        }

        $normalizedPath = ltrim(str_replace('\\', '/', $path), '/');

        if (! Str::startsWith($normalizedPath, "companies/{$company->id}/logo/")) {
            return;
        }

        Storage::disk(config('filesystems.default'))->delete($normalizedPath);
    }
}
