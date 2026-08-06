<?php

namespace App\Champs\Services;

use App\Champs\Contracts\InstagramEnricherInterface;
use App\Champs\Contracts\InstagramResolverInterface;
use App\Champs\DTOs\InstagramProfileData;
use App\Champs\DTOs\ResolvedInstagramProfile;
use App\Champs\Exceptions\InstagramEnrichmentException;
use App\Champs\Exceptions\InstagramResolutionException;
use App\Models\ChampsLead;
use App\Models\ChampsSearch;
use App\Models\ChampsSearchResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ChampsLeadEnrichmentService
{
    public function __construct(
        private readonly InstagramResolverInterface $resolver,
        private readonly InstagramEnricherInterface $enricher,
        private readonly ChampsLeadScoringService $scoring,
    ) {}

    public function enrich(ChampsLead $lead): ChampsLead
    {
        $companyId = (int) $lead->company_id;
        $leadId = (int) $lead->getKey();
        $website = trim((string) $lead->website);

        if ($companyId < 1 || $leadId < 1) {
            throw new InvalidArgumentException('The lead must be persisted and assigned to a company.');
        }

        if ($website === '') {
            return $lead;
        }

        try {
            $resolvedProfile = $this->resolver->resolve($website);
        } catch (InstagramResolutionException) {
            return $lead;
        }

        if ($resolvedProfile === null) {
            return $lead;
        }

        $profileData = null;

        if ($this->enricher->isAvailable()) {
            try {
                $profileData = $this->enricher->enrich($resolvedProfile->username);
            } catch (InstagramEnrichmentException) {
                $profileData = null;
            }
        }

        return $this->persistAndRescore(
            companyId: $companyId,
            leadId: $leadId,
            resolvedProfile: $resolvedProfile,
            profileData: $profileData,
        );
    }

    private function persistAndRescore(
        int $companyId,
        int $leadId,
        ResolvedInstagramProfile $resolvedProfile,
        ?InstagramProfileData $profileData,
    ): ChampsLead {
        return DB::transaction(function () use (
            $companyId,
            $leadId,
            $resolvedProfile,
            $profileData,
        ): ChampsLead {
            $lead = ChampsLead::query()
                ->forCompany($companyId)
                ->whereKey($leadId)
                ->lockForUpdate()
                ->firstOrFail();

            $attributes = [
                'instagram_username' => $resolvedProfile->username,
                'instagram_profile_url' => $resolvedProfile->profileUrl,
                'source_data' => $this->sourceDataWithResolution($lead, $resolvedProfile),
            ];

            if ($profileData?->enrichable) {
                $attributes['instagram_username'] = $profileData->username;
                $attributes['instagram_profile_url'] = 'https://www.instagram.com/'
                    .$profileData->username.'/';
                $attributes['instagram_is_professional'] = $profileData->isProfessional;

                if ($profileData->followersCount !== null) {
                    $attributes['instagram_followers_count'] = $profileData->followersCount;
                }

                if ($profileData->mediaCount !== null) {
                    $attributes['instagram_media_count'] = $profileData->mediaCount;
                }
            }

            $lead->fill($attributes)->save();
            $score = $this->scoring->calculate($lead);
            $affectedSearchIds = [];
            $results = ChampsSearchResult::query()
                ->forCompany($companyId)
                ->where('lead_id', $lead->id)
                ->whereHas('search', fn ($query) => $query->forCompany($companyId))
                ->with('search')
                ->get();

            foreach ($results as $result) {
                $search = $result->search;

                if ($search === null || (int) $search->company_id !== $companyId) {
                    continue;
                }

                $result->update([
                    'score' => $score['score'],
                    'classification' => $score['classification'],
                    'reasons' => $score['reasons'],
                    'criteria' => $score['criteria'],
                    'qualified' => $score['score'] >= (int) $search->minimum_score,
                ]);
                $affectedSearchIds[] = (int) $search->id;
            }

            foreach (array_unique($affectedSearchIds) as $searchId) {
                $totalQualified = ChampsSearchResult::query()
                    ->forCompany($companyId)
                    ->where('search_id', $searchId)
                    ->where('qualified', true)
                    ->count();

                ChampsSearch::query()
                    ->forCompany($companyId)
                    ->whereKey($searchId)
                    ->update(['total_qualified' => $totalQualified]);
            }

            return $lead->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceDataWithResolution(
        ChampsLead $lead,
        ResolvedInstagramProfile $profile,
    ): array {
        $sourceData = is_array($lead->source_data) ? $lead->source_data : [];
        $sourceData['instagram_resolution'] = [
            'confidence' => $profile->confidence,
            'source_url' => $profile->sourceUrl,
            'requires_review' => $profile->requiresReview,
            'candidates' => $profile->candidates,
        ];

        return $sourceData;
    }
}
