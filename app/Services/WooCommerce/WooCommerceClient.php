<?php

namespace App\Services\WooCommerce;

use App\Exceptions\Security\UnsafeOutboundDestinationException;
use App\Models\WooCommerceIntegration;
use App\Services\Security\OutboundDestinationGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
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
 */
class WooCommerceClient
{
    private const MAX_REDIRECTS = 3;

    public function __construct(
        private readonly WooCommerceIntegration $integration,
        private readonly OutboundDestinationGuard $guard,
    ) {}

    public static function for(WooCommerceIntegration $integration): self
    {
        return new self($integration, app(OutboundDestinationGuard::class));
    }

    /** A lightweight authenticated probe used by "Test connection". */
    public function ping(): void
    {
        // `products?per_page=1` requires both a reachable wc/v3 route and valid auth.
        $this->get('products', ['per_page' => 1]);
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
        $all = [];
        $page = 1;
        $perPage = (int) config('woocommerce.per_page', 50);
        $maxPages = (int) config('woocommerce.max_pages', 2000);

        do {
            $result = $this->paginatedList('products/categories', ['per_page' => $perPage, 'page' => $page]);
            $all = array_merge($all, $result['items']);
            $totalPages = $result['total_pages'];
            $page++;
        } while ($page <= $totalPages && $page <= $maxPages);

        return $all;
    }

    /** @return array<int, array<string, mixed>> */
    public function getTaxClasses(): array
    {
        try {
            return $this->get('taxes/classes')->json() ?? [];
        } catch (WooCommerceApiException) {
            return [];
        }
    }

    /** Woo "General" settings — used to detect whether prices include tax. */
    public function getPricesIncludeTax(): ?bool
    {
        try {
            $value = $this->get('settings/general/woocommerce_prices_include_tax')->json('value');

            return match ((string) $value) {
                'yes' => true,
                'no' => false,
                default => null,
            };
        } catch (WooCommerceApiException) {
            return null;
        }
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

    /** @param array<string, mixed> $query */
    private function get(string $path, array $query = []): Response
    {
        $url = $this->absoluteUrl($path);
        $pin = $this->guardUrl($url);

        for ($redirects = 0; ; $redirects++) {
            try {
                $response = $this->request($pin)->get($url, $query);
            } catch (ConnectionException) {
                throw new WooCommerceApiException(WooCommerceApiException::UNREACHABLE, 'Boutique inaccessible.');
            } catch (RequestException $exception) {
                throw $this->classifyStatus($exception->response->status());
            }

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                break;
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

        if ($response->successful()) {
            return $response;
        }

        throw $this->classifyStatus($response->status());
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
            $status === 429 => new WooCommerceApiException(WooCommerceApiException::TRANSIENT, 'WooCommerce a temporairement refusé la requête.'),
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
            ->timeout((int) config('woocommerce.request_timeout', 30))
            ->retry(
                (int) config('woocommerce.retry_times', 2),
                (int) config('woocommerce.retry_backoff_ms', 500),
                // Only retry genuinely transient conditions.
                when: fn ($exception, $request) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && in_array($exception->response->status(), [429, 500, 502, 503, 504], true)),
                throw: true,
            );
    }
}
