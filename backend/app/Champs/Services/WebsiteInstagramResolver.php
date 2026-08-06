<?php

namespace App\Champs\Services;

use App\Champs\Contracts\InstagramResolverInterface;
use App\Champs\DTOs\ResolvedInstagramProfile;
use App\Champs\Exceptions\InstagramResolutionException;
use App\Champs\Support\InstagramUsername;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class WebsiteInstagramResolver implements InstagramResolverInterface
{
    private const INSTAGRAM_HOSTS = [
        'instagram.com',
        'www.instagram.com',
        'm.instagram.com',
    ];

    private const IGNORED_INSTAGRAM_PATHS = [
        'accounts',
        'direct',
        'explore',
        'p',
        'reel',
        'reels',
        'share',
        'sharing',
        'stories',
        'tv',
    ];

    private const RESERVED_INSTAGRAM_NAMES = [
        'about',
        'developer',
        'directory',
        'legal',
        'privacy',
        'terms',
        'web',
    ];

    private int $requestCount = 0;

    public function __construct(
        private readonly int $timeoutSeconds = 5,
        private readonly int $maxRedirects = 3,
        private readonly int $maxRequests = 5,
        private readonly int $maxResponseBytes = 1_048_576,
    ) {}

    public function resolve(string $websiteUrl): ?ResolvedInstagramProfile
    {
        $this->requestCount = 0;
        $homepage = $this->fetchPage($this->normalizeUrl($websiteUrl));
        $homepageDocument = $this->parseHtml($homepage['body']);
        $candidates = [];
        $order = 0;

        $this->collectInstagramCandidates(
            document: $homepageDocument,
            sourceUrl: $homepage['url'],
            fromHomepage: true,
            candidates: $candidates,
            order: $order,
        );

        if ($candidates === [] && $this->requestCount < $this->maxRequests) {
            $contactUrl = $this->findContactUrl($homepageDocument, $homepage['url']);

            if ($contactUrl !== null) {
                $contactPage = $this->fetchPage($contactUrl);
                $this->collectInstagramCandidates(
                    document: $this->parseHtml($contactPage['body']),
                    sourceUrl: $contactPage['url'],
                    fromHomepage: false,
                    candidates: $candidates,
                    order: $order,
                );
            }
        }

        return $this->selectCandidate($candidates);
    }

    /**
     * @return array{body: string, url: string}
     */
    private function fetchPage(string $initialUrl): array
    {
        $url = $initialUrl;
        $redirects = 0;

        while (true) {
            if ($this->requestCount >= $this->maxRequests) {
                throw InstagramResolutionException::requestLimitReached();
            }

            $destination = $this->validatePublicDestination($url);
            $this->requestCount++;
            $response = $this->sendRequest($destination);

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                if ($redirects >= $this->maxRedirects) {
                    throw InstagramResolutionException::tooManyRedirects();
                }

                $location = trim((string) $response->header('Location'));

                if ($location === '') {
                    throw InstagramResolutionException::requestFailed();
                }

                $url = $this->resolveUrl($destination['url'], $location);
                $redirects++;

                continue;
            }

            if ($response->failed()) {
                throw InstagramResolutionException::requestFailed();
            }

            $contentType = mb_strtolower(trim((string) $response->header('Content-Type')));

            if (! str_starts_with($contentType, 'text/html')
                && ! str_starts_with($contentType, 'application/xhtml+xml')) {
                throw InstagramResolutionException::invalidHtml();
            }

            $contentLength = (int) $response->header('Content-Length');

            if ($contentLength > $this->maxResponseBytes) {
                throw InstagramResolutionException::responseTooLarge();
            }

            $body = $response->body();

            if (strlen($body) > $this->maxResponseBytes) {
                throw InstagramResolutionException::responseTooLarge();
            }

            return ['body' => $body, 'url' => $destination['url']];
        }
    }

    /**
     * @param  array{url: string, host: string, port: int, ip: string}  $destination
     */
    private function sendRequest(array $destination): Response
    {
        $limitExceeded = false;
        $options = [
            'allow_redirects' => false,
            'http_errors' => false,
            'progress' => function (float $downloadTotal, float $downloaded) use (&$limitExceeded): void {
                if (($downloadTotal > 0 && $downloadTotal > $this->maxResponseBytes)
                    || $downloaded > $this->maxResponseBytes) {
                    $limitExceeded = true;

                    throw new RuntimeException('Website response limit exceeded.');
                }
            },
        ];

        $curlOptions = $this->curlOptions($destination);

        if ($curlOptions !== []) {
            $options['curl'] = $curlOptions;
        }

        try {
            return Http::accept('text/html,application/xhtml+xml')
                ->withUserAgent('ChampsWebsiteInstagramResolver/1.0')
                ->connectTimeout(min(3, $this->timeoutSeconds))
                ->timeout($this->timeoutSeconds)
                ->withOptions($options)
                ->get($destination['url']);
        } catch (ConnectionException|RuntimeException $exception) {
            if ($limitExceeded) {
                throw InstagramResolutionException::responseTooLarge();
            }

            throw InstagramResolutionException::requestFailed();
        }
    }

    /**
     * @param  array{url: string, host: string, port: int, ip: string}  $destination
     * @return array<int, mixed>
     */
    private function curlOptions(array $destination): array
    {
        if (! defined('CURLOPT_RESOLVE') || filter_var($destination['host'], FILTER_VALIDATE_IP)) {
            return [];
        }

        $ip = str_contains($destination['ip'], ':')
            ? '['.$destination['ip'].']'
            : $destination['ip'];
        $options = [
            CURLOPT_RESOLVE => ["{$destination['host']}:{$destination['port']}:{$ip}"],
        ];

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        return $options;
    }

    private function parseHtml(string $html): DOMDocument
    {
        if ($html === '' || str_contains($html, "\0")
            || ! preg_match('/<(?:!doctype\s+html|html|head|body|a)\b/i', $html)) {
            throw InstagramResolutionException::invalidHtml();
        }

        $document = new DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        if (! $loaded) {
            throw InstagramResolutionException::invalidHtml();
        }

        return $document;
    }

    /**
     * @param  array<string, array{count: int, source_url: string, homepage: bool, order: int}>  $candidates
     */
    private function collectInstagramCandidates(
        DOMDocument $document,
        string $sourceUrl,
        bool $fromHomepage,
        array &$candidates,
        int &$order,
    ): void {
        $links = (new DOMXPath($document))->query('//a[@href]');

        if ($links === false) {
            return;
        }

        foreach ($links as $link) {
            $href = $link->attributes?->getNamedItem('href')?->nodeValue;

            if (! is_string($href)) {
                continue;
            }

            $username = $this->instagramUsernameFromUrl($href, $sourceUrl);

            if ($username === null) {
                continue;
            }

            if (! isset($candidates[$username])) {
                $candidates[$username] = [
                    'count' => 0,
                    'source_url' => $sourceUrl,
                    'homepage' => $fromHomepage,
                    'order' => $order++,
                ];
            }

            $candidates[$username]['count']++;
            $candidates[$username]['homepage'] = $candidates[$username]['homepage'] || $fromHomepage;
        }
    }

    private function instagramUsernameFromUrl(string $href, string $sourceUrl): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (preg_match('/^(?:www\.)?instagram\.com\//i', $href)) {
            $href = 'https://'.$href;
        }

        try {
            $url = $this->resolveUrl($sourceUrl, $href);
        } catch (InstagramResolutionException) {
            return null;
        }

        $parts = parse_url($url);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, self::INSTAGRAM_HOSTS, true)) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', trim((string) ($parts['path'] ?? ''), '/')),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($segments === []) {
            return null;
        }

        $firstSegment = mb_strtolower(ltrim(rawurldecode($segments[0]), '@'));

        if (in_array($firstSegment, self::IGNORED_INSTAGRAM_PATHS, true)
            || in_array($firstSegment, self::RESERVED_INSTAGRAM_NAMES, true)) {
            return null;
        }

        return InstagramUsername::normalize($segments[0]);
    }

    private function findContactUrl(DOMDocument $document, string $pageUrl): ?string
    {
        $pageHost = mb_strtolower((string) parse_url($pageUrl, PHP_URL_HOST));
        $links = (new DOMXPath($document))->query('//a[@href]');

        if ($links === false) {
            return null;
        }

        foreach ($links as $link) {
            $href = $link->attributes?->getNamedItem('href')?->nodeValue;

            if (! is_string($href)) {
                continue;
            }

            $label = Str::lower(Str::ascii(Str::squish($link->textContent.' '.$href)));

            if (! Str::contains($label, ['contato', 'contact', 'fale-conosco', 'fale conosco'])) {
                continue;
            }

            try {
                $contactUrl = $this->resolveUrl($pageUrl, $href);
            } catch (InstagramResolutionException) {
                continue;
            }

            if (mb_strtolower((string) parse_url($contactUrl, PHP_URL_HOST)) === $pageHost) {
                return $contactUrl;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{count: int, source_url: string, homepage: bool, order: int}>  $candidates
     */
    private function selectCandidate(array $candidates): ?ResolvedInstagramProfile
    {
        if ($candidates === []) {
            return null;
        }

        uasort($candidates, static fn (array $left, array $right): int => $right['count'] <=> $left['count'] ?: $left['order'] <=> $right['order']);

        $usernames = array_keys($candidates);
        $username = $usernames[0];
        $winner = $candidates[$username];
        $requiresReview = count($usernames) > 1;
        $confidence = match (true) {
            $requiresReview => ResolvedInstagramProfile::CONFIDENCE_LOW,
            $winner['homepage'] => ResolvedInstagramProfile::CONFIDENCE_HIGH,
            default => ResolvedInstagramProfile::CONFIDENCE_MEDIUM,
        };

        return new ResolvedInstagramProfile(
            username: $username,
            profileUrl: "https://www.instagram.com/{$username}/",
            confidence: $confidence,
            sourceUrl: $this->publicSourceUrl($winner['source_url']),
            requiresReview: $requiresReview,
            candidates: $usernames,
        );
    }

    private function publicSourceUrl(string $url): string
    {
        return (string) (new Uri($url))->withQuery('')->withFragment('');
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw InstagramResolutionException::invalidUrl();
        }

        try {
            return (string) (new Uri($url))->withFragment('');
        } catch (Throwable) {
            throw InstagramResolutionException::invalidUrl();
        }
    }

    private function resolveUrl(string $baseUrl, string $reference): string
    {
        try {
            $resolved = UriResolver::resolve(new Uri($baseUrl), new Uri(trim($reference)));
        } catch (Throwable) {
            throw InstagramResolutionException::invalidUrl();
        }

        $scheme = mb_strtolower($resolved->getScheme());

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw InstagramResolutionException::invalidUrl();
        }

        return (string) $resolved->withFragment('');
    }

    /**
     * @return array{url: string, host: string, port: int, ip: string}
     */
    private function validatePublicDestination(string $url): array
    {
        $url = $this->normalizeUrl($url);
        $parts = parse_url($url);
        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = mb_strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            throw InstagramResolutionException::invalidUrl();
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (! in_array($port, [80, 443], true) || $this->isInternalHostname($host)) {
            throw InstagramResolutionException::unsafeDestination();
        }

        if ($host === 'instagram.com' || str_ends_with($host, '.instagram.com')) {
            throw InstagramResolutionException::unsafeDestination();
        }

        $ips = $this->resolveHostIps($host);

        if ($ips === []) {
            throw InstagramResolutionException::hostResolutionFailed();
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw InstagramResolutionException::unsafeDestination();
            }
        }

        usort($ips, static fn (string $left, string $right): int => (int) str_contains($left, ':') <=> (int) str_contains($right, ':'));

        return [
            'url' => $url,
            'host' => $host,
            'port' => $port,
            'ip' => $ips[0],
        ];
    }

    private function isInternalHostname(string $host): bool
    {
        if (in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            return true;
        }

        foreach ([
            '.localhost',
            '.local',
            '.internal',
            '.lan',
            '.home',
            '.corp',
            '.example',
            '.invalid',
            '.test',
        ] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;

                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                }
            }
        }

        if ($ips === []) {
            $ipv4Addresses = @gethostbynamel($host);

            if (is_array($ipv4Addresses)) {
                $ips = array_merge($ips, $ipv4Addresses);
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
