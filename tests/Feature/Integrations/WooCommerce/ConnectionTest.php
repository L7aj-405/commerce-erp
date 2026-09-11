<?php

namespace Tests\Feature\Integrations\WooCommerce;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\WooCommerceTestCase;

class ConnectionTest extends WooCommerceTestCase
{
    public function test_a_valid_authenticated_probe_reports_success_in_french(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $this->fakeWooStore(products: []);

        $response = $this->actingAs($owner)
            ->postJson(route('integrations.woocommerce.test', $integration));

        $response->assertOk()->assertJson(['ok' => true, 'message' => 'Connexion réussie.']);
        $this->assertTrue($integration->fresh()->last_connection_ok);
    }

    public function test_invalid_credentials_report_the_french_auth_message(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        Http::fake(['*' => Http::response(['code' => 'woocommerce_rest_authentication_error'], 401)]);

        $this->actingAs($owner)
            ->postJson(route('integrations.woocommerce.test', $integration))
            ->assertOk()
            ->assertJson(['ok' => false, 'message' => 'Identifiants WooCommerce invalides.']);

        $this->assertFalse($integration->fresh()->last_connection_ok);
    }

    public function test_an_unreachable_host_reports_the_french_unreachable_message(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $this->actingAs($owner)
            ->postJson(route('integrations.woocommerce.test', $integration))
            ->assertOk()
            ->assertJson(['ok' => false, 'message' => 'Boutique inaccessible.']);
    }

    public function test_a_wordpress_without_the_rest_api_reports_api_unavailable(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        Http::fake(['*' => Http::response('<!DOCTYPE html><title>Not found</title>', 404)]);

        $this->actingAs($owner)
            ->postJson(route('integrations.woocommerce.test', $integration))
            ->assertOk()
            ->assertJson(['ok' => false, 'message' => 'WooCommerce REST API indisponible.']);
    }

    public function test_the_probe_never_echoes_the_consumer_secret(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        Http::fake(['*' => Http::response("bad key {$this->secret}", 401)]);

        $response = $this->actingAs($owner)
            ->postJson(route('integrations.woocommerce.test', $integration));

        $this->assertStringNotContainsString($this->secret, $response->getContent());
    }

    public function test_a_member_without_the_manage_permission_cannot_test_the_connection(): void
    {
        [, $organization, , , $integration] = $this->wooContext();
        $member = User::factory()->create();
        $this->addOrganizationMember($organization, $member, ['integrations.view']);
        $this->activate($member, $organization);

        $this->actingAs($member)
            ->postJson(route('integrations.woocommerce.test', $integration))
            ->assertForbidden();
    }

    public function test_a_foreign_tenant_cannot_reach_another_organizations_integration(): void
    {
        [, , , , $integration] = $this->wooContext();

        $outsider = User::factory()->create();
        $otherOrg = $this->createOrganization($outsider, 'Other Org');
        $this->activate($outsider, $otherOrg);

        $this->actingAs($outsider)
            ->post(route('integrations.woocommerce.test', $integration))
            ->assertNotFound();
    }

    public function test_the_settings_page_never_exposes_the_stored_secret(): void
    {
        [$owner, , , , $integration] = $this->wooContext();

        $response = $this->actingAs($owner)->get(route('integrations.woocommerce.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Integrations/WooCommerce')
            ->where('integration.has_secret', true)
            ->where('integration.id', $integration->getKey())
            ->missing('integration.consumer_secret'));

        $this->assertStringNotContainsString($this->secret, $response->getContent());
    }
}
