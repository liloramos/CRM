<?php

namespace App\Champs\Services;

use App\Champs\Contracts\LeadProviderInterface;
use App\Champs\Contracts\PaginatedLeadProviderInterface;
use App\Champs\DTOs\DiscoveredLead;
use App\Champs\DTOs\LeadDiscoveryRequest;
use App\Champs\Exceptions\ChampsSearchException;
use App\Champs\Exceptions\LeadProviderException;
use App\Champs\Support\ChampsSearchFingerprint;
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
        bool $excludeSeen = true,
    ): ChampsSearch {
        $companyId = $this->companyId($user);

        if ($minimumScore < 0 || $minimumScore > 100) {
            throw new InvalidArgumentException('Minimum score must be between 0 and 100.');
        }

        $discoveryRequest = new LeadDiscoveryRequest($niche, $city, $state, $limit);
        $providerName = $this->provider->name();
        $searchName = $this->searchName($name, $discoveryRequest);
        $searchFingerprint = ChampsSearchFingerprint::make(
            $discoveryRequest->niche,
            $discoveryRequest->city,
            $discoveryRequest->state,
            $providerName,
        );

        $this->backfillLegacyFingerprints($companyId);

        $search = ChampsSearch::query()->create([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'name' => $searchName,
            'niche' => $discoveryRequest->niche,
            'city' => $discoveryRequest->city,
            'state' => mb_strtoupper($discoveryRequest->state),
            'requested_limit' => $discoveryRequest->limit,
            'provider' => $providerName,
            'search_fingerprint' => $searchFingerprint,
            'exclude_seen' => $excludeSeen,
            'minimum_score' => $minimumScore,
            'status' => ChampsSearch::STATUS_PROCESSING,
            'started_at' => now(),
        ]);

        try {
            $seenExternalIds = $excludeSeen
                ? $this->seenExternalIds($companyId, $providerName, $searchFingerprint)
                : [];
            $discoverySummary = $this->discoverProgressively(
                request: $discoveryRequest,
                providerName: $providerName,
                seenExternalIds: $seenExternalIds,
                excludeSeen: $excludeSeen,
            );
            $discoveredLeads = $discoverySummary['discoveries'];
            $savedLeadIds = [];

            DB::transaction(function () use (
                $search,
                $discoveredLeads,
                $discoverySummary,
                $providerName,
                $companyId,
                $minimumScore,
                &$savedLeadIds,
            ): void {
                $saved = 0;
                $qualifiedCount = 0;

                foreach ($discoveredLeads as $discovered) {
                    $lead = $this->upsertLead(
                        companyId: $companyId,
                        providerName: $providerName,
                        discoveredLead: $discovered['lead'],
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
                            'position' => $discovered['position'],
                        ],
                    );

                    $saved++;
                    $qualifiedCount += $qualified ? 1 : 0;
                    $savedLeadIds[] = (int) $lead->id;
                }

                $search->update([
                    'total_discovered' => count($discoveredLeads),
                    'total_saved' => $saved,
                    'total_qualified' => $qualifiedCount,
                    'total_scanned' => $discoverySummary['total_scanned'],
                    'total_skipped_seen' => $discoverySummary['total_skipped_seen'],
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
     * @param  array<string, true>  $seenExternalIds
     * @return array{
     *     discoveries: list<array{lead: DiscoveredLead, position: int}>,
     *     total_scanned: int,
     *     total_skipped_seen: int
     * }
     */
    private function discoverProgressively(
        LeadDiscoveryRequest $request,
        string $providerName,
        array $seenExternalIds,
        bool $excludeSeen,
    ): array {
        $discoveries = [];
        $scannedExternalIds = [];
        $totalSkippedSeen = 0;
        $pageToken = null;
        $pageCount = 0;
        $maxPages = max(1, (int) config('champs.google_places.max_pages', 3));
        $paginatedProvider = $this->provider instanceof PaginatedLeadProviderInterface
            ? $this->provider
            : null;

        do {
            if ($paginatedProvider !== null) {
                $page = $paginatedProvider->discoverPage($request, $pageToken);
                $providerLeads = $page->leads;
                $nextPageToken = $page->nextPageToken;
            } else {
                $providerLeads = $this->provider->discover($request);
                $nextPageToken = null;
            }

            $pageCount++;

            foreach ($providerLeads as $lead) {
                if (! $lead instanceof DiscoveredLead) {
                    throw ChampsSearchException::processingFailed();
                }

                $externalId = trim($lead->externalId);

                if ($externalId === '') {
                    continue;
                }

                $key = $providerName.'|'.$externalId;

                if (isset($scannedExternalIds[$key])) {
                    continue;
                }

                $scannedExternalIds[$key] = true;

                if ($excludeSeen && isset($seenExternalIds[$externalId])) {
                    $totalSkippedSeen++;

                    continue;
                }

                $discoveries[] = [
                    'lead' => $lead,
                    'position' => count($discoveries) + 1,
                ];

                if (count($discoveries) >= $request->limit) {
                    break 2;
                }
            }

            $pageToken = $nextPageToken;
        } while (
            $paginatedProvider !== null
            && $pageToken !== null
            && $pageCount < $maxPages
        );

        return [
            'discoveries' => $discoveries,
            'total_scanned' => count($scannedExternalIds),
            'total_skipped_seen' => $totalSkippedSeen,
        ];
    }

    /**
     * @return array<string, true>
     */
    private function seenExternalIds(
        int $companyId,
        string $providerName,
        string $searchFingerprint,
    ): array {
        return DB::table('champs_search_results as result')
            ->join('champs_searches as search', 'search.id', '=', 'result.search_id')
            ->join('champs_leads as lead', 'lead.id', '=', 'result.lead_id')
            ->where('search.company_id', $companyId)
            ->where('search.search_fingerprint', $searchFingerprint)
            ->where('search.provider', $providerName)
            ->where('lead.company_id', $companyId)
            ->where('lead.provider', $providerName)
            ->distinct()
            ->pluck('lead.external_id')
            ->mapWithKeys(fn (string $externalId): array => [$externalId => true])
            ->all();
    }

    private function backfillLegacyFingerprints(int $companyId): void
    {
        ChampsSearch::query()
            ->forCompany($companyId)
            ->whereNull('search_fingerprint')
            ->get(['id', 'niche', 'city', 'state', 'provider'])
            ->each(function (ChampsSearch $search): void {
                $search->forceFill([
                    'search_fingerprint' => ChampsSearchFingerprint::make(
                        $search->niche,
                        $search->city,
                        $search->state,
                        $search->provider,
                    ),
                ])->saveQuietly();
            });
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
