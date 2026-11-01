<?php

namespace App\Services\WooCommerce;

use App\Exceptions\Security\UnsafeOutboundDestinationException;
use App\Models\WooCommerceIntegration;
use App\Services\Security\OutboundDestinationGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only place the application talks to a WooCommerce store. All calls are
 * server-side, authenticated with HTTP Basic Auth (consumer key : consumer
 * secret) over the configured wc/v3 base URL. Every failure is normalised to a
 * WooCommerceApiException with a safe category — no secrets, bodies or traces.
 *
 * SSRF hardening: every request (and every redirect hop) is validated through
 * {@see OutboundDestinationGuard} immediately before it is issued. Automatic
 * redirect-following is disabled — a redirect Location is only followed after
 * it independently passes the same guard, so a validated public store URL
 * cannot be used to reach a private address via a 3xx response. Redirects must
 * also remain on the configured origin: consumer credentials are never forwarded
 * to an unrelated public host or alternate port.
 *
 * DNS-rebinding closure (Sprint 1.1 §1): validation alone still trusts Guzzle
 * to re-resolve the hostname itself when it opens the socket, which can
 * answer differently than our check a moment earlier. Every request (and
 * redirect hop) additionally pins the connection to the exact address the
 * guard just validated via cURL's `CURLOPT_RESOLVE` (Laravel's HTTP client
 * passes the `curl` request option straight through to `curl_setopt()`) —
 * the request URL itself is untouched, so TLS SNI/certificate verification
 * and the Host header still use the real hostname; only the low-level
 * connect target is fixed. `verify` is never touched (stays enabled).
 *
 * Rate limits (F3): every call of one client instance (one per sync run /
 * connection test) shares ONE request policy ({@see WooCommerceRetryPolicy}):
 *  - pacing: a minimum interval between two requests, so a sync never fires
 *    product-page → variations → variations bursts;
 *  - 429: Retry-After honoured (seconds or HTTP-date) and never shortened —
 *    a wait longer than the configured maximum stops the run as rate-limited
 *    instead; without Retry-After, exponential backoff with jitter;
 *  - 500/502/503/504 and connection failures: bounded exponential backoff;
 *  - 401/403, 404 and other 4xx: never retried;
 *  - a total backoff budget per run keeps the queue job far below its timeout.
 * Every retry / final failure emits a safe diagnostic event (endpoint path,
 * page, product id, status, attempt, Retry-After, rate-limit headers, duration)
 * — never the consumer key/secret, the Authorization header or a body.
 */
class WooCommerceClient
{
    private const MAX_REDIRECTS = 3;

    /** Response headers worth recording when a request is throttled (lower-case). */
    private const RATE_LIMIT_HEADERS = [
        'retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset',
        'ratelimit-limit', 'ratelimit-remaining', 'ratelimit-reset', 'ratelimit-policy',
    ];

    private WooCommerceRetryPolicy $policy;

    private WooCommerceClock $clock;

    /** @var (callable(array<string, mixed>): void)|null */
    private $listener = null;

    private ?float $lastRequestAt = null;

    private float $totalBackoff = 0.0;

    private int $requestCount = 0;

    /** @var array<int, array<string, mixed>>|null run-level memo of the category tree */
    private ?array $categories = null;

    public function __construct(
        private readonly WooCommerceIntegration $integration,
        private readonly OutboundDestinationGuard $guard,
        ?WooCommerceRetryPolicy $policy = null,
        ?WooCommerceClock $clock = null,
    ) {
        $this->policy = $policy ?? WooCommerceRetryPolicy::forSync();
        $this->clock = $clock ?? app(WooCommerceClock::class);
    }

    public static function for(WooCommerceIntegration $integration): self
    {
        return new self($integration, app(OutboundDestinationGuard::class));
    }

    /**
     * Receive safe diagnostic events: `retrying` (before a backoff wait),
     * `recovered` (a retried request finally succeeded) and `failed`.
     *
     * @param  callable(array<string, mixed>): void  $listener
     */
    public function onEvent(callable $listener): self
    {
        $this->listener = $listener;

        return $this;
    }

    /** HTTP requests actually sent by this client (attempts included). */
    public function requestCount(): int
    {
        return $this->requestCount;
    }

    /** Seconds spent waiting on backoff so far (pacing excluded). */
    public function totalBackoffSeconds(): float
    {
        return $this->totalBackoff;
    }

    /** A lightweight authenticated probe used by "Test connection". */
    public function ping(): void
    {
        // `products?per_page=1` requires both a reachable wc/v3 route and valid auth.
        // Interactive: at most one short retry — a web request never waits minutes.
        $this->get('products', ['per_page' => 1], WooCommerceRetryPolicy::interactive());
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{items: array<int, array<string, mixed>>, total_pages: int, total: int}
     */
    public function getProducts(array $query = []): array
    {
        return $this->paginatedList('products', $query);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{items: array<int, array<string, mixed>>, total_pages: int, total: int}
     */
    public function getProductVariations(int $productId, array $query = []): array
    {
        return $this->paginatedList("products/{$productId}/variations", $query);
    }

    /**
     * Every product category in the store (walks all pages). Used once per run to
     * build the remote id -> {name, parent} tree.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllProductCategories(): array
    {
        if ($this->categories !== null) {
            return $this->categories; // already loaded during this run
        }

        $all = [];
        $page = 1;
        $perPage = self::perPage();
        $maxPages = (int) config('woocommerce.max_pages', 2000);

        do {
            $result = $this->paginatedList('products/categories', ['per_page' => $perPage, 'page' => $page]);
            $all = array_merge($all, $result['items']);
            $totalPages = $result['total_pages'];
            $page++;
        } while ($page <= $totalPages && $page <= $maxPages);

        return $this->categories = $all;
    }

    /**
     * Woo "General" settings — whether prices include tax. Null when the setting
     * holds no recognised value; a failed request throws (the caller decides
     * whether to fall back, and records it — never a silent default).
     */
    public function getPricesIncludeTax(): ?bool
    {
        $value = $this->get('settings/general/woocommerce_prices_include_tax')->json('value');

        return match ((string) $value) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    /** Items per page: the configured value, within the WP REST limit (1..100). */
    public static function perPage(): int
    {
        return max(1, min(100, (int) config('woocommerce.per_page', 100)));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{items: array<int, array<string, mixed>>, total_pages: int, total: int}
     */
    private function paginatedList(string $path, array $query): array
    {
        $response = $this->get($path, $query);
        $items = $response->json();

        if (! is_array($items)) {
            throw new WooCommerceApiException(WooCommerceApiException::INVALID_RESPONSE, 'Réponse WooCommerce inattendue.');
        }

        return [
            'items' => $items,
            'total_pages' => (int) ($response->header('X-WP-TotalPages') ?: 1),
            'total' => (int) ($response->header('X-WP-Total') ?: count($items)),
        ];
    }

    /**
     * One logical GET: paced, retried according to the policy, diagnosed.
     *
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = [], ?WooCommerceRetryPolicy $policy = null): Response
    {
        $policy ??= $this->policy;
        $retries = 0;

        while (true) {
            $this->pace($policy);
            $started = $this->clock->now();
            $response = null;
            $connectionFailed = false;

            try {
                $response = $this->send($path, $query);
            } catch (ConnectionException) {
                $connectionFailed = true;
            } finally {
                $this->lastRequestAt = $this->clock->now();
                $this->requestCount++;
            }

            $event = $this->diagnostics($path, $query, $response, $connectionFailed, $retries + 1, $policy, $this->lastRequestAt - $started);

            if ($response !== null && $response->successful()) {
                if ($retries > 0) {
                    $this->emit('recovered', $event);
                }

                return $response;
            }

            $status = $response?->status();
            $retryable = $connectionFailed || ($status !== null && $policy->isRetryableStatus($status));
            if (! $retryable) {
                throw $this->fail($event, $connectionFailed ? $this->unreachable() : $this->classifyStatus((int) $status));
            }

            // How long the server asked us to wait (429, or 503 maintenance).
            $retryAfter = $response ? WooCommerceRetryPolicy::retryAfterSeconds($response->header('Retry-After'), $this->clock->now()) : null;
            $event['retry_after_seconds'] = $retryAfter;
            $final = $connectionFailed ? $this->unreachable() : $this->classifyStatus((int) $status);

            if ($retries >= $policy->maxRetries) {
                throw $this->fail($event, $final);
            }
            if ($retryAfter !== null && $retryAfter > $policy->maxDelaySeconds) {
                // Never shortened: stop and say when the store accepts requests again.
                throw $this->fail($event + ['reason' => 'retry_after_exceeds_max_delay'], $final);
            }

            $delay = $retryAfter ?? $policy->backoff($retries + 1, $this->clock->jitter());
            if ($this->totalBackoff + $delay > $policy->maxTotalBackoffSeconds) {
                throw $this->fail($event + ['reason' => 'backoff_budget_exhausted'], $final);
            }

            $retries++;
            $this->emit('retrying', $event + [
                'retry_in_seconds' => $delay,
                'retry_at' => date(DATE_ATOM, (int) ceil($this->clock->now() + $delay)),
                'next_attempt' => $retries + 1,
            ]);
            $this->clock->sleep($delay);
            $this->totalBackoff += $delay;
        }
    }

    /**
     * One HTTP attempt, following (and re-validating) redirects. Returns the
     * final response whatever its status; throws ConnectionException on a
     * network failure and WooCommerceApiException on an unsafe redirect.
     *
     * @param  array<string, mixed>  $query
     */
    private function send(string $path, array $query): Response
    {
        $url = $this->absoluteUrl($path);
        $pin = $this->guardUrl($url);

        for ($redirects = 0; ; $redirects++) {
            $response = $this->request($pin)->get($url, $query);

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            if ($redirects >= self::MAX_REDIRECTS) {
                throw new WooCommerceApiException(WooCommerceApiException::INVALID_RESPONSE, 'Trop de redirections WooCommerce.');
            }

            $location = $response->header('Location');
            if (! $location) {
                throw new WooCommerceApiException(WooCommerceApiException::INVALID_RESPONSE, 'Réponse WooCommerce inattendue.');
            }

            // A redirect target already carries its own query string (or none
            // relevant to us); the original $query was for the pre-redirect URL.
            // Every hop is independently resolved, validated AND re-pinned —
            // a validated public store URL redirecting to a private address
            // (or to one that now resolves privately) is never followed.
            $redirectUrl = $this->resolveAgainst($url, $location);
            if (! $this->hasSameOrigin($url, $redirectUrl)) {
                throw new WooCommerceApiException(
                    WooCommerceApiException::INVALID_RESPONSE,
                    'Une redirection WooCommerce vers un autre hôte a été refusée.',
                );
            }
            $url = $redirectUrl;
            $pin = $this->guardUrl($url);
            $query = [];
        }
    }

    /** Minimum spacing between two requests of this client (no bursts). */
    private function pace(WooCommerceRetryPolicy $policy): void
    {
        if ($this->lastRequestAt === null || $policy->minIntervalSeconds <= 0) {
            return;
        }
        $wait = $policy->minIntervalSeconds - ($this->clock->now() - $this->lastRequestAt);
        if ($wait > 0) {
            $this->clock->sleep($wait);
        }
    }

    /**
     * Safe diagnostics of one attempt. The path is relative to wc/v3 (the store
     * host and credentials are never part of it); only paging parameters of the
     * query are kept.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function diagnostics(string $path, array $query, ?Response $response, bool $connectionFailed, int $attempt, WooCommerceRetryPolicy $policy, float $duration): array
    {
        $endpoint = preg_replace('#products/\d+/variations#', 'products/{id}/variations', ltrim($path, '/'));
        preg_match('#products/(\d+)/variations#', $path, $product);

        $rateLimit = [];
        if ($response !== null) {
            foreach (self::RATE_LIMIT_HEADERS as $header) {
                $value = $response->header($header);
                if ($value !== '') {
                    $rateLimit[$header] = mb_substr($value, 0, 64);
                }
            }
        }

        return [
            'endpoint' => $endpoint,
            'page' => isset($query['page']) ? (int) $query['page'] : null,
            'per_page' => isset($query['per_page']) ? (int) $query['per_page'] : null,
            'product_id' => isset($product[1]) ? (int) $product[1] : null,
            'status' => $response?->status(),
            'error' => $connectionFailed ? 'connection' : null,
            'attempt' => $attempt,
            'max_attempts' => $policy->maxRetries + 1,
            'rate_limit_headers' => $rateLimit,
            'duration_ms' => (int) round(max(0, $duration) * 1000),
        ];
    }

    /** @param array<string, mixed> $event */
    private function fail(array $event, WooCommerceApiException $exception): WooCommerceApiException
    {
        $this->emit('failed', $event);

        return $exception->withContext($event);
    }

    /** @param array<string, mixed> $event */
    private function emit(string $type, array $event): void
    {
        $context = ['integration_id' => $this->integration->getKey(), 'organization_id' => $this->integration->organization_id, 'event' => $type, ...$event];
        if ($type !== 'recovered') {
            Log::warning('woocommerce.request_'.$type, $context);
        }
        if ($this->listener) {
            ($this->listener)(['type' => $type, ...$event]);
        }
    }

    private function unreachable(): WooCommerceApiException
    {
        return new WooCommerceApiException(WooCommerceApiException::UNREACHABLE, 'Boutique inaccessible.');
    }

    /** Resolves a Location header (absolute URL or absolute path) against the current request URL. */
    private function resolveAgainst(string $currentUrl, string $location): string
    {
        $location = trim($location);
        $locationParts = parse_url($location);

        if ($locationParts !== false && ! empty($locationParts['scheme']) && ! empty($locationParts['host'])) {
            return $location;
        }

        if (str_starts_with($location, '/')) {
            $current = parse_url($currentUrl);
            if ($current === false || empty($current['scheme']) || empty($current['host'])) {
                throw new WooCommerceApiException(WooCommerceApiException::INVALID_RESPONSE, 'Réponse WooCommerce inattendue.');
            }
            $port = isset($current['port']) ? ':'.$current['port'] : '';

            return "{$current['scheme']}://{$current['host']}{$port}{$location}";
        }

        // A bare relative (non-absolute-path) redirect is not something a
        // legitimate wc/v3 endpoint issues — reject rather than guess.
        throw new WooCommerceApiException(WooCommerceApiException::INVALID_RESPONSE, 'Réponse WooCommerce inattendue.');
    }

    private function hasSameOrigin(string $currentUrl, string $redirectUrl): bool
    {
        $current = parse_url($currentUrl);
        $redirect = parse_url($redirectUrl);

        if ($current === false || $redirect === false) {
            return false;
        }

        $currentScheme = strtolower((string) ($current['scheme'] ?? ''));
        $redirectScheme = strtolower((string) ($redirect['scheme'] ?? ''));
        $currentPort = (int) ($current['port'] ?? ($currentScheme === 'https' ? 443 : 80));
        $redirectPort = (int) ($redirect['port'] ?? ($redirectScheme === 'https' ? 443 : 80));

        return $currentScheme === $redirectScheme
            && strcasecmp((string) ($current['host'] ?? ''), (string) ($redirect['host'] ?? '')) === 0
            && $currentPort === $redirectPort;
    }

    /** @return array{host: string, port: int, address: string} */
    private function guardUrl(string $url): array
    {
        try {
            return $this->guard->assertPublicUrlPin($url, ['https'], 'WooCommerce');
        } catch (UnsafeOutboundDestinationException $exception) {
            Log::warning('woocommerce.destination_rejected', [
                'integration_id' => $this->integration->getKey(),
                'organization_id' => $this->integration->organization_id,
                'category' => $exception->category,
            ]);

            throw new WooCommerceApiException(WooCommerceApiException::SSRF_BLOCKED, $exception->userMessage());
        }
    }

    private function absoluteUrl(string $path): string
    {
        return rtrim($this->integration->apiBaseUrl(), '/').'/'.ltrim($path, '/');
    }

    private function classifyStatus(int $status): WooCommerceApiException
    {
        return match (true) {
            $status === 401 || $status === 403 => new WooCommerceApiException(WooCommerceApiException::AUTH, 'Identifiants WooCommerce invalides.'),
            $status === 404 => new WooCommerceApiException(WooCommerceApiException::API_UNAVAILABLE, 'WooCommerce REST API indisponible.'),
            $status === 429 => new WooCommerceApiException(WooCommerceApiException::RATE_LIMITED, 'Limite de requêtes WooCommerce atteinte.'),
            $status >= 500 => new WooCommerceApiException(WooCommerceApiException::API_UNAVAILABLE, 'WooCommerce REST API indisponible.'),
            default => new WooCommerceApiException(WooCommerceApiException::INVALID_RESPONSE, 'Réponse WooCommerce inattendue.'),
        };
    }

    /** @param array{host: string, port: int, address: string} $pin */
    private function request(array $pin): PendingRequest
    {
        // Absolute URLs only (see absoluteUrl()/resolveAgainst()) — no baseUrl()
        // here, and automatic redirect-following is disabled so every hop is
        // re-validated by guardUrl() before it is ever requested (see get()).
        //
        // CURLOPT_RESOLVE pins this exact request's connection to the address
        // guardUrl() just validated, closing the DNS-rebinding TOCTOU window
        // between that check and the socket cURL would otherwise re-resolve
        // and open itself (see the class doc). No brackets around an IPv6
        // address here — curl's own "HOST:PORT:ADDRESS" parser splits on the
        // first two colons only and expects the address bare.
        return Http::withOptions([
            'allow_redirects' => false,
            'curl' => [CURLOPT_RESOLVE => ["{$pin['host']}:{$pin['port']}:{$pin['address']}"]],
        ])
            ->withBasicAuth($this->integration->consumer_key, $this->integration->consumer_secret)
            ->acceptJson()
            ->connectTimeout((int) config('woocommerce.connect_timeout', 10))
            // No HTTP-client retry here: get() owns the rate-limit-aware retry
            // policy (pacing, Retry-After, backoff) for every attempt.
            ->timeout((int) config('woocommerce.request_timeout', 30));
    }
}
