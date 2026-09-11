<?php

namespace App\Services\WooCommerce;

use App\Models\WooCommerceIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only place the application talks to a WooCommerce store. All calls are
 * server-side, authenticated with HTTP Basic Auth (consumer key : consumer
 * secret) over the configured wc/v3 base URL. Every failure is normalised to a
 * WooCommerceApiException with a safe category — no secrets, bodies or traces.
 */
class WooCommerceClient
{
    public function __construct(private readonly WooCommerceIntegration $integration) {}

    public static function for(WooCommerceIntegration $integration): self
    {
        return new self($integration);
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
        try {
            $response = $this->request()->get($path, $query);
        } catch (ConnectionException) {
            throw new WooCommerceApiException(WooCommerceApiException::UNREACHABLE, 'Boutique inaccessible.');
        } catch (RequestException $exception) {
            throw $this->classifyStatus($exception->response->status());
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->classifyStatus($response->status());
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

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->integration->apiBaseUrl().'/')
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
