<?php

namespace Tests\Feature\Integrations\WooCommerce;

use App\Actions\WooCommerce\TestWooCommerceConnectionAction;
use App\Jobs\SyncWooCommerceProductsJob;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductChannelIdentifier;
use App\Models\ProductVariant;
use App\Models\WooCommerceSyncRun;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\WooCommerceTestCase;

/**
 * F3 — WooCommerce synchronization against realistic rate limits. A scripted
 * fake store answers each endpoint from a queue of responses; the fake clock
 * records every pacing / backoff wait without sleeping.
 */
class RateLimitTest extends WooCommerceTestCase
{
    /** @var list<array{path: string, query: array<string, string>}> */
    private array $requests = [];

    /** @var array<string, list<array{0: mixed, 1: int, 2: array<string, mixed>}>> */
    private array $script = [];

    // ── 429 handling ─────────────────────────────────────────────────────

    public function test_the_connection_test_succeeds_while_the_heavier_sync_is_rate_limited(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $this->store([
            'probe' => [[[['id' => 1]], 200, []]],
            'categories' => [[['code' => 'too_many_requests'], 429, []]],
        ]);

        $test = app(TestWooCommerceConnectionAction::class)->execute($owner, $integration->fresh());
        $run = $this->runSync($integration, $owner);

        $this->assertSame(['ok' => true, 'message' => 'Connexion réussie.'], $test);
        $this->assertSame(WooCommerceSyncRun::STATUS_FAILED, $run->status);
        $this->assertSame('rate_limited', $run->failure_reason);
        $this->assertSame('catégories WooCommerce — page 1', $run->failed_during);
        $this->assertStringContainsString('Limite de requêtes WooCommerce atteinte (HTTP 429)', $run->message);
        // One request + 5 controlled retries, then stop — products never hammered.
        $this->assertSame(6, $this->requestsTo('products/categories'));
        $this->assertSame(0, $this->requestsTo('products', exact: true, excludeProbe: true));
        $this->assertSame(6, $run->rate_limit_hits);
    }

    public function test_retry_after_seconds_is_honored(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $this->store([
            'products' => [
                [[], 429, ['Retry-After' => '5']],
                [[$this->wooProductPayload(['id' => 11, 'sku' => 'A-11'])], 200, $this->pages(1, 1)],
            ],
        ]);

        $run = $this->runSync($integration, $owner);

        $this->assertSame(WooCommerceSyncRun::STATUS_COMPLETED, $run->status);
        $this->assertSame([5.0], $this->wooClock->backoffs());
        $this->assertSame(1, $run->products_created);
        $this->assertSame(1, $run->rate_limit_hits);
        $retry = collect($run->api_diagnostics)->firstWhere('type', 'retrying');
        $this->assertEquals(['endpoint' => 'products', 'page' => 1, 'status' => 429, 'retry_after_seconds' => 5, 'retry_in_seconds' => 5],
            array_intersect_key($retry, array_flip(['endpoint', 'page', 'status', 'retry_after_seconds', 'retry_in_seconds'])));
        $this->assertSame('5', $retry['rate_limit_headers']['retry-after']);
        $this->assertSame('produits WooCommerce — page 1', $retry['location']);
    }

    public function test_retry_after_as_an_http_date_is_honored(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $at = gmdate('D, d M Y H:i:s \G\M\T', (int) $this->wooClock->time + 7);
        $this->store(['products' => [[[], 429, ['Retry-After' => $at]], [[], 200, $this->pages(0, 1)]]]);

        $this->runSync($integration, $owner);

        // The date is absolute: two pacing waits (0.25 s each) already elapsed
        // before the 429, so exactly the remaining 6.5 s is waited — never less.
        $this->assertSame([6.5], $this->wooClock->backoffs());
    }

    public function test_without_retry_after_the_backoff_is_exponential_with_bounded_jitter(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $this->store(['products' => [
            [[], 429, []], [[], 429, []], [[], 429, []],
            [[], 200, $this->pages(0, 1)],
        ]]);

        $this->runSync($integration, $owner);
        $this->assertSame([1.0, 2.0, 4.0], $this->wooClock->backoffs());

        // Jitter (±25 %) never exceeds the configured bounds.
        $this->wooClock->sleeps = [];
        $this->wooClock->jitterValue = 1.0;
        $this->store(['products' => [[[], 429, []], [[], 429, []], [[], 200, $this->pages(0, 1)]]]);
        $this->runSync($integration, $owner);
        $this->assertSame([1.25, 2.5], $this->wooClock->backoffs());
    }

    public function test_repeated_429_ends_in_a_clear_rate_limited_failure(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $this->store(['products' => [[['code' => 'too_many_requests'], 429, ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '0']]]]);

        $run = $this->runSync($integration, $owner);

        $this->assertSame(WooCommerceSyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(WooCommerceSyncRun::PHASE_FAILED, $run->phase);
        $this->assertSame('rate_limited', $run->failure_reason);
        $this->assertSame('produits WooCommerce — page 1', $run->failed_during);
        $this->assertStringContainsString('relancer la synchronisation reprend depuis le début sans créer de doublons', $run->message);
        $this->assertSame([1.0, 2.0, 4.0, 8.0, 16.0], $this->wooClock->backoffs());
        $this->assertSame(6, $this->requestsTo('products', exact: true));
        $failed = collect($run->api_diagnostics)->last();
        $this->assertSame(['type' => 'failed', 'status' => 429, 'attempt' => 6, 'max_attempts' => 6],
            array_intersect_key($failed, array_flip(['type', 'status', 'attempt', 'max_attempts'])));
        $this->assertSame(['x-ratelimit-limit' => '60', 'x-ratelimit-remaining' => '0'], $failed['rate_limit_headers']);
    }

    public function test_a_retry_after_beyond_the_maximum_wait_is_never_shortened(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $this->store(['products' => [[[], 429, ['Retry-After' => '600']]]]);

        $run = $this->runSync($integration, $owner);

        $this->assertSame('rate_limited', $run->failure_reason);
        $this->assertSame([], $this->wooClock->backoffs());
        $this->assertSame(1, $this->requestsTo('products', exact: true));
        $this->assertStringContainsString('dans 600 s', $run->message);
    }

    public function test_the_total_backoff_budget_of_a_run_is_bounded(): void
    {
        config(['woocommerce.max_total_backoff_seconds' => 10]);
        [$owner, , , , $integration] = $this->wooContext();
        $this->store(['products' => [[[], 429, []]]]);

        $run = $this->runSync($integration, $owner);

        $this->assertSame([1.0, 2.0, 4.0], $this->wooClock->backoffs()); // 7 s; +8 s would exceed 10 s
        $this->assertSame('rate_limited', $run->failure_reason);
        $this->assertSame('backoff_budget_exhausted', collect($run->api_diagnostics)->last()['reason']);
    }

    // ── other statuses ───────────────────────────────────────────────────

    public function test_authentication_failures_are_never_retried(): void
    {
        foreach ([401, 403] as $status) {
            $this->requests = [];
            $this->wooClock->sleeps = [];
            [$owner, , , , $integration] = $this->wooContext();
            $this->store(['products' => [[['code' => 'woocommerce_rest_cannot_view'], $status, []]]]);

            $run = $this->runSync($integration, $owner);

            $this->assertSame('auth', $run->failure_reason);
            $this->assertSame(1, $this->requestsTo('products', exact: true));
            $this->assertSame([], $this->wooClock->backoffs());
        }
    }

    public function test_server_errors_retry_with_bounded_exponential_backoff(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $this->store(['products' => [[[], 503, []], [[], 502, []], [[$this->wooProductPayload(['id' => 21, 'sku' => 'S-21'])], 200, $this->pages(1, 1)]]]);
        $run = $this->runSync($integration, $owner);
        $this->assertSame(WooCommerceSyncRun::STATUS_COMPLETED, $run->status);
        $this->assertSame([1.0, 2.0], $this->wooClock->backoffs());

        config(['woocommerce.max_retry_delay_seconds' => 5]);
        $this->wooClock->sleeps = [];
        $this->store(['products' => [[[], 500, []]]]);
        $run = $this->runSync($integration, $owner);
        $this->assertSame('api_unavailable', $run->failure_reason);
        $this->assertSame([1.0, 2.0, 4.0, 5.0, 5.0], $this->wooClock->backoffs()); // capped at the max delay
    }

    public function test_the_interactive_connection_test_never_waits_long(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $this->store(['probe' => [[[], 429, ['Retry-After' => '30']]]]);

        $result = app(TestWooCommerceConnectionAction::class)->execute($owner, $integration->fresh());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('dans 30 s', $result['message']);
        $this->assertSame([], $this->wooClock->backoffs());
    }

    // ── pacing, pagination, idempotency ──────────────────────────────────

    public function test_requests_are_paced_and_paginated_with_100_items_per_page_without_duplicates(): void
    {
        [$owner, $organization, , , $integration] = $this->wooContext();
        $page1 = array_map(fn ($i) => $this->wooProductPayload(['id' => 1000 + $i, 'sku' => "P-{$i}", 'name' => "P {$i}"]), range(1, 3));
        $page2 = array_map(fn ($i) => $this->wooProductPayload(['id' => 1000 + $i, 'sku' => "P-{$i}", 'name' => "P {$i}"]), range(4, 5));
        $script = ['products' => [
            [$page1, 200, $this->pages(5, 2)],
            [[], 429, ['Retry-After' => '2']],
            [$page2, 200, $this->pages(5, 2)],
        ]];

        $this->store($script);
        $first = $this->runSync($integration, $owner);
        $this->store($script);
        $second = $this->runSync($integration, $owner);

        $this->assertSame(5, $first->products_created);
        $this->assertSame([0, 5], [$second->products_created, $second->products_updated]);
        $this->assertSame(5, Product::where('organization_id', $organization->id)->count());
        $this->assertSame(5, ProductVariant::where('organization_id', $organization->id)->count());
        $this->assertSame(5, ProductChannelIdentifier::where('organization_id', $organization->id)->count());
        $this->assertTrue(collect($this->requests)->every(fn ($r) => ! isset($r['query']['per_page']) || $r['query']['per_page'] === '100'));
        // Pacing: every request after the first waited the minimum interval.
        $this->assertContains(0.25, $this->wooClock->sleeps);
        $this->assertSame(['total_pages' => 2, 'pages_read' => 2], $second->progress);
        // Second run: 1 categories + 3 product requests (page 1, page 2 throttled
        // once, page 2). The TTC/HT setting was stored by run 1 and not re-read.
        $this->assertSame(4, $second->api_requests);
    }

    public function test_variable_product_variations_are_paginated_and_retried_without_duplicate_variants(): void
    {
        [$owner, $organization, , , $integration] = $this->wooContext(syncStock: true);
        $parent = $this->wooProductPayload(['id' => 500, 'type' => 'variable', 'sku' => 'VAR', 'name' => 'Variable']);
        $variation = fn (int $id) => $this->wooVariationPayload(['id' => $id, 'sku' => "VAR-{$id}", 'manage_stock' => true, 'stock_quantity' => 3,
            'attributes' => [['name' => 'Size', 'option' => (string) $id]]]);
        $script = [
            'products' => [[[$parent], 200, $this->pages(1, 1)]],
            'variations' => [
                [[$variation(501), $variation(502)], 200, $this->pages(3, 2)],
                [[], 429, ['Retry-After' => '3']],
                [[$variation(503)], 200, $this->pages(3, 2)],
            ],
        ];

        $this->store($script);
        $first = $this->runSync($integration, $owner);
        $this->store($script);
        $second = $this->runSync($integration, $owner);

        $this->assertSame(WooCommerceSyncRun::STATUS_COMPLETED, $second->status);
        $this->assertSame(1, Product::where('organization_id', $organization->id)->count());
        $this->assertSame(3, ProductVariant::where('organization_id', $organization->id)->count());
        $this->assertSame(4, ProductChannelIdentifier::where('organization_id', $organization->id)->count()); // parent + 3 variations
        $this->assertSame(3, $first->stock_adjustments);
        $this->assertSame(0, $second->stock_adjustments); // stock moves only by delta: no duplicate movement
        $this->assertSame(3, InventoryMovement::query()->where('organization_id', $organization->id)->count());
        $this->assertSame([1, 2, 2], collect($this->requests)->filter(fn ($r) => str_contains($r['path'], '/variations'))->map(fn ($r) => (int) $r['query']['page'])->take(3)->values()->all());
    }

    // ── progress, visibility, concurrency, secrets ───────────────────────

    public function test_the_run_exposes_its_phase_and_progress_including_a_rate_limit_wait(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $integration->forceFill(['prices_include_tax' => null])->save();
        $parent = $this->wooProductPayload(['id' => 700, 'type' => 'variable', 'sku' => 'PV']);
        $seen = [];
        $this->store([
            'products' => [[[$parent], 200, $this->pages(1, 1)]],
            'variations' => [[[], 429, ['Retry-After' => '8']], [[$this->wooVariationPayload(['id' => 701, 'sku' => 'PV-1'])], 200, $this->pages(1, 1)]],
        ], function (string $kind) use (&$seen) {
            $run = WooCommerceSyncRun::query()->latest('id')->first();
            $seen[$kind][] = [$run->status, $run->phase, $run->progress['page'] ?? null, $run->progress['product_id'] ?? null];
        });
        $this->wooClock->onSleep = function (float $seconds) use (&$seen) {
            if ($seconds > 1) {
                $run = WooCommerceSyncRun::query()->latest('id')->first();
                $seen['waiting'][] = [$run->status, $run->phase, $run->progress['retry_in_seconds'], $run->progress['resume_phase'], $run->progress['product_id']];
            }
        };

        $run = $this->runSync($integration, $owner);

        $this->assertSame([['running', 'loading_settings', null, null]], $seen['settings']);
        $this->assertSame([['running', 'loading_categories', null, null]], $seen['categories']);
        $this->assertSame([['running', 'loading_products', 1, null]], $seen['products']);
        $this->assertSame(['running', 'loading_variations', 1, 700], $seen['variations'][0]);
        // While waiting out the 429 the run is still RUNNING — shown as rate limited, never failed.
        $this->assertEquals([['running', 'rate_limited', 8, 'loading_variations', 700]], $seen['waiting']);
        $this->assertSame(WooCommerceSyncRun::PHASE_COMPLETED, $run->phase);

        $this->actingAs($owner)->getJson(route('integrations.woocommerce.status', $integration))
            ->assertOk()
            ->assertJsonPath('run.phase', 'completed')
            ->assertJsonPath('run.rate_limit_hits', 1)
            ->assertJsonPath('run.api_diagnostics.0.status', 429);
    }

    public function test_optional_metadata_failures_are_visible_warnings_not_silent(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $integration->forceFill(['prices_include_tax' => null])->save();
        $this->store([
            'settings' => [[[], 404, []]],
            'categories' => [[[], 404, []]],
            'products' => [[[$this->wooProductPayload(['id' => 31, 'sku' => 'M-31'])], 200, $this->pages(1, 1)]],
        ]);

        $run = $this->runSync($integration, $owner);

        $this->assertSame(WooCommerceSyncRun::STATUS_COMPLETED, $run->status);
        $messages = collect($run->errors)->pluck('message')->implode("\n");
        $this->assertStringContainsString('réglage WooCommerce « prix saisis TTC/HT » non lisible', $messages);
        $this->assertStringContainsString('catégories WooCommerce non chargées', $messages);
        $this->assertSame(1, $run->products_created);
    }

    public function test_a_rate_limited_settings_request_stops_the_run_instead_of_guessing_prices(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $integration->forceFill(['prices_include_tax' => null])->save();
        $this->store(['settings' => [[[], 429, ['Retry-After' => '1']]]]);

        $run = $this->runSync($integration, $owner);

        $this->assertSame('rate_limited', $run->failure_reason);
        $this->assertSame('réglages WooCommerce (prix TTC/HT)', $run->failed_during);
        $this->assertNull($integration->fresh()->prices_include_tax);
        $this->assertSame(0, $this->requestsTo('products', exact: true));
    }

    public function test_a_second_sync_cannot_start_while_one_is_running_or_backing_off(): void
    {
        Bus::fake();
        [$owner, , , , $integration] = $this->wooContext();
        $run = new WooCommerceSyncRun;
        $run->forceFill([
            'organization_id' => $integration->organization_id, 'woocommerce_integration_id' => $integration->id,
            'status' => 'running', 'phase' => 'rate_limited', 'progress' => ['retry_in_seconds' => 8], 'started_at' => now(), 'errors' => [],
        ])->save();

        $this->actingAs($owner)->post(route('integrations.woocommerce.sync', $integration))->assertSessionHasErrors('sync');
        Bus::assertNotDispatched(SyncWooCommerceProductsJob::class);
    }

    public function test_a_duplicate_job_is_a_no_op_and_a_failed_job_releases_the_lock(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $this->store([]);
        $live = new WooCommerceSyncRun;
        $live->forceFill([
            'organization_id' => $integration->organization_id, 'woocommerce_integration_id' => $integration->id,
            'status' => 'running', 'phase' => 'loading_products', 'started_at' => now(), 'errors' => [],
        ])->save();
        $lock = Cache::lock('woocommerce-sync:'.$integration->id, 3900);
        $this->assertTrue($lock->get());

        // A second job while the first holds the lock: no request, no failure of the live run.
        (new SyncWooCommerceProductsJob($integration->id, $owner->id))->handle(app(\App\Actions\WooCommerce\SyncWooCommerceProductsAction::class));
        $this->assertSame([], $this->requests);
        $this->assertSame('running', $live->fresh()->status);

        // The first job dies (timeout / worker crash): run closed, lock freed.
        (new SyncWooCommerceProductsJob($integration->id, $owner->id))->failed(new \RuntimeException('timeout'));
        $this->assertSame(['failed', 'failed', 'job_failed'], array_values($live->fresh()->only(['status', 'phase', 'failure_reason'])));
        $this->assertTrue(Cache::lock('woocommerce-sync:'.$integration->id, 10)->get());
    }

    public function test_no_secret_reaches_logs_diagnostics_or_messages(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        $this->store(['products' => [[['message' => "denied {$this->secret}"], 429, ['Retry-After' => '1']]]]);

        $run = $this->runSync($integration, $owner);

        $this->assertNotEmpty($logged);
        $haystack = implode("\n", $logged).json_encode($run->toArray());
        foreach ([$this->secret, 'ck_test_key', 'Authorization', 'Basic '] as $needle) {
            $this->assertStringNotContainsString($needle, $haystack);
        }
        $this->assertStringContainsString('woocommerce.request_retrying', $haystack);
    }

    // ── fake store ───────────────────────────────────────────────────────

    /**
     * Endpoint kinds: probe (products?per_page=1), settings, categories,
     * variations, products. Each kind answers from its queue; the last response
     * repeats. Missing kinds answer an empty 200 page.
     *
     * @param  array<string, list<array{0: mixed, 1: int, 2: array<string, mixed>}>>  $script
     */
    private function store(array $script, ?callable $observe = null): void
    {
        $this->requests = [];
        $this->script = $script;
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($observe) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->requests[] = ['path' => $path, 'query' => $query];
            $kind = match (true) {
                ($query['per_page'] ?? null) === '1' => 'probe',
                str_contains($path, '/settings/') => 'settings',
                str_contains($path, '/products/categories') => 'categories',
                str_contains($path, '/variations') => 'variations',
                default => 'products',
            };
            if ($observe && $kind !== 'probe') {
                $observe($kind);
            }
            $queue = $this->script[$kind] ?? [[$kind === 'settings' ? ['value' => 'yes'] : [], 200, $this->pages(0, 1)]];
            $next = count($queue) > 1 ? array_shift($this->script[$kind]) : $queue[0];

            return Http::response($next[0], $next[1], $next[2]);
        });
    }

    /** @return array<string, int> */
    private function pages(int $total, int $pages): array
    {
        return ['X-WP-Total' => $total, 'X-WP-TotalPages' => $pages];
    }

    private function requestsTo(string $endpoint, bool $exact = false, bool $excludeProbe = true): int
    {
        return collect($this->requests)->filter(function ($r) use ($endpoint, $exact, $excludeProbe) {
            if ($excludeProbe && ($r['query']['per_page'] ?? null) === '1') {
                return false;
            }

            return $exact ? str_ends_with($r['path'], '/wc/v3/'.$endpoint) : str_contains($r['path'], '/wc/v3/'.$endpoint);
        })->count();
    }
}
