<?php

namespace Tests\Feature\Integrations\WooCommerce;

use App\Models\User;
use App\Models\WooCommerceIntegration;
use App\Models\WooCommerceSyncRun;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Support\Facades\Http;
use Tests\Support\WooCommerceTestCase;

/**
 * SSRF hardening for the WooCommerce store URL (§A). Save-time validation is
 * on WooCommerceIntegrationController; request-time (defense in depth against
 * DNS changing after save) and redirect-hop validation are on
 * WooCommerceClient — see its class doc.
 */
class WooCommerceSsrfTest extends WooCommerceTestCase
{
    public function test_saving_an_integration_with_a_localhost_store_url_is_rejected(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->post(route('integrations.woocommerce.store'), [
            'name' => 'Boutique malveillante',
            'store_url' => 'https://localhost/wp-json',
            'consumer_key' => 'ck_x',
            'consumer_secret' => 'cs_x',
        ])->assertSessionHasErrors('store_url');

        $this->assertSame(0, WooCommerceIntegration::query()->count());
    }

    public function test_saving_an_integration_whose_host_resolves_to_a_private_ip_is_rejected(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $this->fakeDns()->map('internal-shop.test', ['10.0.0.9']);

        $this->actingAs($owner)->post(route('integrations.woocommerce.store'), [
            'name' => 'Boutique interne',
            'store_url' => 'https://internal-shop.test/',
            'consumer_key' => 'ck_x',
            'consumer_secret' => 'cs_x',
        ])->assertSessionHasErrors('store_url');

        $this->assertSame(0, WooCommerceIntegration::query()->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'woocommerce.destination_rejected']);
    }

    public function test_a_genuinely_public_store_url_saves_normally(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->post(route('integrations.woocommerce.store'), [
            'name' => 'Boutique publique',
            'store_url' => 'https://shop.example.com',
            'consumer_key' => 'ck_x',
            'consumer_secret' => 'cs_x',
        ])->assertRedirect();

        $this->assertSame(1, WooCommerceIntegration::query()->count());
    }

    public function test_the_public_woocommerce_store_with_a_dns64_answer_saves_normally(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $this->fakeDns()->map('avprofessional-store.ma', [
            '198.177.120.96',
            '64:ff9b::c6b1:7860',
        ]);

        $this->actingAs($owner)->post(route('integrations.woocommerce.store'), [
            'name' => 'AV Professional',
            'store_url' => 'https://avprofessional-store.ma',
            'consumer_key' => 'ck_x',
            'consumer_secret' => 'cs_x',
        ])->assertRedirect();

        $this->assertDatabaseHas('woocommerce_integrations', [
            'organization_id' => $organization->getKey(),
            'store_url' => 'https://avprofessional-store.ma',
        ]);
    }

    public function test_a_sync_request_is_rejected_at_request_time_if_dns_now_resolves_privately(): void
    {
        // Saved when the host was still public (DNS-rebinding scenario) —
        // WooCommerceClient must re-validate at request time, not trust the
        // save-time check forever.
        [$owner, , , , $integration] = $this->wooContext();
        $this->fakeDns()->map(parse_url($integration->store_url, PHP_URL_HOST), ['127.0.0.1']);

        $this->fakeWooStore(products: [$this->wooProductPayload()]);
        $run = $this->runSync($integration, $owner);

        $this->assertSame(WooCommerceSyncRun::STATUS_FAILED, $run->status);
    }

    public function test_a_redirect_to_a_private_address_is_never_followed(): void
    {
        [$owner, , , , $integration] = $this->wooContext();

        Http::fake([
            '*/wp-json/wc/v3/products*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
            '*' => Http::response([], 200),
        ]);

        $run = $this->runSync($integration, $owner);

        $this->assertSame(WooCommerceSyncRun::STATUS_FAILED, $run->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
    }

    public function test_a_redirect_to_another_public_host_is_rejected_without_forwarding_credentials(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $this->fakeDns()->map('new-shop.example.com', ['93.184.216.34']);

        Http::fake([
            '*/wp-json/wc/v3/products*' => Http::response('', 301, ['Location' => 'https://new-shop.example.com/wp-json/wc/v3/products?per_page=50&page=1']),
            'https://new-shop.example.com/*' => Http::response([$this->wooProductPayload()], 200, ['X-WP-Total' => 1, 'X-WP-TotalPages' => 1]),
            '*' => Http::response([], 200, ['X-WP-Total' => 0, 'X-WP-TotalPages' => 1]),
        ]);

        $run = $this->runSync($integration, $owner);

        $this->assertSame(WooCommerceSyncRun::STATUS_FAILED, $run->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'new-shop.example.com'));
    }

    public function test_a_same_origin_redirect_is_revalidated_and_followed(): void
    {
        [, , , , $integration] = $this->wooContext();

        Http::fake([
            'https://shop.test/redirected-products*' => Http::response([], 200, ['X-WP-Total' => 0, 'X-WP-TotalPages' => 1]),
            'https://shop.test/wp-json/wc/v3/products*' => Http::response('', 302, ['Location' => 'https://shop.test/redirected-products']),
        ]);

        WooCommerceClient::for($integration)->ping();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/redirected-products'));
    }
}
