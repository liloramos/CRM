<?php

namespace App\Champs\Providers;

use App\Champs\Contracts\LeadProviderInterface;
use App\Champs\Contracts\PaginatedLeadProviderInterface;
use App\Champs\DTOs\DiscoveredLead;
use App\Champs\DTOs\LeadDiscoveryPage;
use App\Champs\DTOs\LeadDiscoveryRequest;
use App\Champs\Exceptions\LeadProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

final class GooglePlacesLeadProvider implements LeadProviderInterface, PaginatedLeadProviderInterface
{
    public const FIELD_MASK = [
        'places.id',
        'places.displayName',
        'places.formattedAddress',
        'places.businessStatus',
        'places.websiteUri',
        'places.nationalPhoneNumber',
        'places.rating',
        'places.userRatingCount',
        'nextPageToken',
    ];

    public function name(): string
    {
        return 'google_places';
    }

    public function discover(LeadDiscoveryRequest $request): array
    {
        $leads = [];
        $seenPlaceIds = [];
        $pageToken = null;
        $pageCount = 0;

        do {
            $page = $this->discoverPage($request, $pageToken);
            $pageCount++;

            foreach ($page->leads as $lead) {
                if (isset($seenPlaceIds[$lead->externalId])) {
                    continue;
                }

                $seenPlaceIds[$lead->externalId] = true;
                $leads[] = $lead;

                if (count($leads) >= $request->limit) {
                    break 2;
                }
            }

            $pageToken = $page->nextPageToken;
        } while ($pageToken !== null && $pageCount < $this->maxPages());

        return $leads;
    }

    public function discoverPage(
        LeadDiscoveryRequest $request,
        ?string $pageToken = null,
    ): LeadDiscoveryPage {
        $this->ensureConfigured();

        $payload = [
            'textQuery' => $request->textQuery(),
            'pageSize' => min(20, $request->limit),
            'languageCode' => $this->language(),
            'regionCode' => $this->region(),
        ];

        if ($pageToken !== null) {
            $normalizedPageToken = trim($pageToken);

            if ($normalizedPageToken === '') {
                throw LeadProviderException::invalidResponse();
            }

            $payload['pageToken'] = $normalizedPageToken;
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders([
                    'X-Goog-Api-Key' => $this->apiKey(),
                    'X-Goog-FieldMask' => implode(',', self::FIELD_MASK),
                ])
                ->timeout($this->timeout())
                ->post($this->endpoint(), $payload);
        } catch (ConnectionException) {
            throw LeadProviderException::connectionFailure();
        }

        $this->ensureSuccessfulResponse($response);

        return $this->mapResponse($response, $request);
    }

    private function ensureConfigured(): void
    {
        if (! (bool) config('champs.google_places.enabled', false)) {
            throw LeadProviderException::disabled();
        }

        if ($this->apiKey() === '') {
            throw LeadProviderException::missingApiKey();
        }

        if ($this->baseUrl() === '' || $this->timeout() < 1 || $this->maxPages() < 1) {
            throw LeadProviderException::invalidConfiguration();
        }
    }

    private function ensureSuccessfulResponse(Response $response): void
    {
        $status = $response->status();

        if (in_array($status, [401, 403], true)) {
            throw LeadProviderException::credentialsRejected($status);
        }

        if ($status === 429) {
            throw LeadProviderException::rateLimited();
        }

        if ($response->serverError()) {
            throw LeadProviderException::serverFailure($status);
        }

        if ($response->failed()) {
            throw LeadProviderException::requestFailure($status);
        }
    }

    private function mapResponse(Response $response, LeadDiscoveryRequest $request): LeadDiscoveryPage
    {
        $body = trim($response->body());

        if ($body === '') {
            return new LeadDiscoveryPage([]);
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw LeadProviderException::invalidResponse();
        }

        if (! is_array($payload)) {
            throw LeadProviderException::invalidResponse();
        }

        $places = $payload['places'] ?? [];

        if (! is_array($places)) {
            throw LeadProviderException::invalidResponse();
        }

        $leads = [];
        $seenPlaceIds = [];
        $nextPageToken = $this->nextPageToken($payload);

        foreach ($places as $place) {
            if (! is_array($place)) {
                throw LeadProviderException::invalidResponse();
            }

            $placeId = $this->nullableString($place['id'] ?? null);

            if ($placeId === null || isset($seenPlaceIds[$placeId])) {
                continue;
            }

            $seenPlaceIds[$placeId] = true;
            $displayName = is_array($place['displayName'] ?? null)
                ? $this->nullableString($place['displayName']['text'] ?? null)
                : null;

            $leads[] = new DiscoveredLead(
                provider: $this->name(),
                externalId: $placeId,
                name: $displayName ?? 'Sem nome',
                formattedAddress: $this->nullableString($place['formattedAddress'] ?? null) ?? '',
                city: $request->city,
                state: $request->state,
                phone: $this->nullableString($place['nationalPhoneNumber'] ?? null),
                website: $this->nullableString($place['websiteUri'] ?? null),
                rating: is_numeric($place['rating'] ?? null) ? (float) $place['rating'] : null,
                userRatingCount: is_numeric($place['userRatingCount'] ?? null)
                    ? max(0, (int) $place['userRatingCount'])
                    : 0,
                businessStatus: $this->nullableString($place['businessStatus'] ?? null),
                rawData: $place,
            );

            if (count($leads) >= min(20, $request->limit)) {
                break;
            }
        }

        return new LeadDiscoveryPage($leads, $nextPageToken);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nextPageToken(array $payload): ?string
    {
        if (! array_key_exists('nextPageToken', $payload) || $payload['nextPageToken'] === null) {
            return null;
        }

        if (! is_string($payload['nextPageToken'])) {
            throw LeadProviderException::invalidResponse();
        }

        $token = trim($payload['nextPageToken']);

        return $token === '' ? null : $token;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function apiKey(): string
    {
        return trim((string) config('champs.google_places.api_key', ''));
    }

    private function baseUrl(): string
    {
        return rtrim(trim((string) config('champs.google_places.base_url', '')), '/');
    }

    private function endpoint(): string
    {
        return $this->baseUrl().'/places:searchText';
    }

    private function language(): string
    {
        return trim((string) config('champs.google_places.language', 'pt-BR'));
    }

    private function region(): string
    {
        return trim((string) config('champs.google_places.region', 'BR'));
    }

    private function timeout(): int
    {
        return (int) config('champs.google_places.timeout', 15);
    }

    private function maxPages(): int
    {
        return (int) config('champs.google_places.max_pages', 3);
    }
}
