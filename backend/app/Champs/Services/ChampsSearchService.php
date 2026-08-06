<?php

namespace App\Champs\Services;

use App\Champs\Contracts\LeadProviderInterface;
use App\Champs\DTOs\DiscoveredLead;
use App\Champs\DTOs\LeadDiscoveryRequest;
use App\Champs\Exceptions\ChampsSearchException;
use App\Champs\Exceptions\LeadProviderException;
use App\Models\ChampsLead;
use App\Models\ChampsSearch;
use App\Models\ChampsSearchResult;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ChampsSearchService
{
    public function __construct(
        private readonly LeadProviderInterface $provider,
        private readonly ChampsLeadScoringService $scoring,
        private readonly ChampsLeadEnrichmentService $enrichment,
    ) {}

    public function execute(
        User $user,
        string $niche,
        string $city,
        string $state,
        int $limit,
        ?string $name = null,
        int $minimumScore = 0,
    ): ChampsSearch {
        $companyId = $this->companyId($user);

        if ($minimumScore < 0 || $minimumScore > 100) {
            throw new InvalidArgumentException('Minimum score must be between 0 and 100.');
        }

        $discoveryRequest = new LeadDiscoveryRequest($niche, $city, $state, $limit);
        $providerName = $this->provider->name();
        $searchName = $this->searchName($name, $discoveryRequest);

        $search = ChampsSearch::query()->create([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'name' => $searchName,
            'niche' => $discoveryRequest->niche,
            'city' => $discoveryRequest->city,
            'state' => mb_strtoupper($discoveryRequest->state),
            'requested_limit' => $discoveryRequest->limit,
            'provider' => $providerName,
            'minimum_score' => $minimumScore,
            'status' => ChampsSearch::STATUS_PROCESSING,
            'started_at' => now(),
        ]);

        try {
            $providerLeads = $this->provider->discover($discoveryRequest);
            $totalDiscovered = count($providerLeads);
            $discoveredLeads = $this->uniqueDiscoveries($providerLeads, $providerName);
            $savedLeadIds = [];

            DB::transaction(function () use (
                $search,
                $discoveredLeads,
                $totalDiscovered,
                $providerName,
                $companyId,
                $minimumScore,
                &$savedLeadIds,
            ): void {
                $saved = 0;
                $qualifiedCount = 0;

                foreach ($discoveredLeads as $discovery) {
                    $lead = $this->upsertLead(
                        companyId: $companyId,
                        providerName: $providerName,
                        discoveredLead: $discovery['lead'],
                    );

                    $score = $this->scoring->calculate($lead);
                    $qualified = $score['score'] >= $minimumScore;

                    ChampsSearchResult::query()->updateOrCreate(
                        [
                            'search_id' => $search->id,
                            'lead_id' => $lead->id,
                        ],
                        [
                            'company_id' => $companyId,
                            'score' => $score['score'],
                            'classification' => $score['classification'],
                            'reasons' => $score['reasons'],
                            'criteria' => $score['criteria'],
                            'qualified' => $qualified,
                            'position' => $discovery['position'],
                        ],
                    );

                    $saved++;
                    $qualifiedCount += $qualified ? 1 : 0;
                    $savedLeadIds[] = (int) $lead->id;
                }

                $search->update([
                    'total_discovered' => $totalDiscovered,
                    'total_saved' => $saved,
                    'total_qualified' => $qualifiedCount,
                    'error_message' => null,
                ]);
            });

            $this->enrichLeads($companyId, $savedLeadIds);
            $search->update([
                'status' => ChampsSearch::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $safeMessage = $this->safeFailureMessage($exception);
            $this->markFailed($search, $safeMessage);

            if ($exception instanceof LeadProviderException) {
                throw new LeadProviderException($safeMessage);
            }

            throw ChampsSearchException::processingFailed();
        }

        return $search->refresh()->load([
            'results' => fn ($query) => $query->orderBy('position')->orderBy('id'),
            'results.lead',
        ]);
    }

    private function companyId(User $user): int
    {
        if ($user->company_id === null) {
            throw new AuthorizationException('Authenticated user is not assigned to a company.');
        }

        return (int) $user->company_id;
    }

    private function searchName(?string $name, LeadDiscoveryRequest $request): string
    {
        $normalizedName = Str::squish((string) $name);

        if ($normalizedName === '') {
            $normalizedName = "{$request->niche} em {$request->city} {$request->state}";
        }

        return Str::limit($normalizedName, 120, '');
    }

    /**
     * @param  list<DiscoveredLead>  $discoveredLeads
     * @return list<array{lead: DiscoveredLead, position: int}>
     */
    private function uniqueDiscoveries(array $discoveredLeads, string $providerName): array
    {
        $unique = [];
        $seen = [];

        foreach ($discoveredLeads as $index => $lead) {
            if (! $lead instanceof DiscoveredLead) {
                throw ChampsSearchException::processingFailed();
            }

            $externalId = trim($lead->externalId);

            if ($externalId === '') {
                continue;
            }

            $key = $providerName.'|'.$externalId;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = [
                'lead' => $lead,
                'position' => $index + 1,
            ];
        }

        return $unique;
    }

    private function upsertLead(
        int $companyId,
        string $providerName,
        DiscoveredLead $discoveredLead,
    ): ChampsLead {
        $lead = ChampsLead::query()->firstOrNew([
            'company_id' => $companyId,
            'provider' => $providerName,
            'external_id' => trim($discoveredLead->externalId),
        ]);

        $attributes = [
            'name' => trim($discoveredLead->name) ?: ($lead->name ?: 'Sem nome'),
            'formatted_address' => $this->incomingOrExisting(
                $discoveredLead->formattedAddress,
                $lead->formatted_address,
            ),
            'city' => $this->incomingOrExisting($discoveredLead->city, $lead->city),
            'state' => $this->incomingOrExisting(
                $discoveredLead->state === null ? null : mb_strtoupper($discoveredLead->state),
                $lead->state,
            ),
            'phone' => $this->incomingOrExisting($discoveredLead->phone, $lead->phone),
            'email' => $this->incomingOrExisting($discoveredLead->email, $lead->email),
            'website' => $this->incomingOrExisting($discoveredLead->website, $lead->website),
            'rating' => $discoveredLead->rating ?? $lead->rating,
            'user_rating_count' => max(
                (int) ($lead->user_rating_count ?? 0),
                $discoveredLead->userRatingCount,
            ),
            'business_status' => $this->incomingOrExisting(
                $discoveredLead->businessStatus,
                $lead->business_status,
            ),
        ];

        if ($discoveredLead->rawData !== null) {
            $attributes['source_data'] = $discoveredLead->rawData;
        }

        $lead->fill($attributes)->save();

        return $lead->refresh();
    }

    private function incomingOrExisting(?string $incoming, ?string $existing): ?string
    {
        $normalized = $incoming === null ? null : trim($incoming);

        return $normalized === null || $normalized === '' ? $existing : $normalized;
    }

    private function safeFailureMessage(Throwable $exception): string
    {
        $message = $exception instanceof LeadProviderException
            ? $exception->getMessage()
            : ChampsSearchException::processingFailed()->getMessage();

        $apiKey = trim((string) config('champs.google_places.api_key', ''));

        if ($apiKey !== '') {
            $message = str_replace($apiKey, '[redacted]', $message);
        }

        return Str::limit($message, 1000, '');
    }

    private function markFailed(ChampsSearch $search, string $message): void
    {
        $search->update([
            'status' => ChampsSearch::STATUS_FAILED,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  list<int>  $leadIds
     */
    private function enrichLeads(int $companyId, array $leadIds): void
    {
        foreach (array_unique($leadIds) as $leadId) {
            $lead = ChampsLead::query()
                ->forCompany($companyId)
                ->whereKey($leadId)
                ->first();

            if ($lead === null || trim((string) $lead->website) === '') {
                continue;
            }

            try {
                $this->enrichment->enrich($lead);
            } catch (Throwable) {
                report(new RuntimeException(
                    "Instagram enrichment failed for Champs lead {$leadId}.",
                ));
            }
        }
    }
}
