<?php

namespace Tests\Feature\Sandbox;

use App\Models\SalesOrder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

/** Bridge phase: the sandbox-safe modules are reachable from Test Mode, everything else stays guarded. */
class SandboxModulesAccessTest extends QuotationTestCase
{
    public function test_supported_modules_are_open_blocked_domains_stay_guarded_and_production_is_unchanged(): void
    {
        [$owner, $org] = $this->quotationCatalogFixture();
        $warehouse = $this->createWarehouse($org, 'Main', 'MAIN');
        $this->actingAs($owner);

        // Production first: unrestricted.
        $production = ['/finance', '/inventory/warehouses', '/inventory/transfer-requests', '/catalog/products', '/integrations/woocommerce'];
        foreach ($production as $url) {
            $this->get($url)->assertOk();
        }
        $this->postJson('/sales/orders/999999/fulfill')->assertNotFound(); // reaches the controller, no Test guard

        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect('/test-mode');

        // Test Mode home: current sandbox, available modules, unavailable list.
        $this->get('/test-mode')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Sandbox/Index')
            ->where('sandbox.active', true)
            ->where('current.name', 'Test')
            ->has('modules', 12)
            ->where('modules.0.href', '/pos')
            ->has('unavailable'));

        // Supported modules are reachable.
        foreach (['/pos', '/quotations', '/sales/orders', '/invoices', '/delivery-notes', '/inventory/stock', '/inventory/movements', '/inventory/transfers', '/inventory/transfers/create', '/payments'] as $url) {
            $this->get($url)->assertOk();
        }
        // A Test POS cart can be created and discarded.
        $cart = $this->postJson('/pos/drafts', ['warehouse_id' => $warehouse->id])->assertOk()->json('active_sale.id');
        $this->deleteJson("/pos/drafts/{$cart}")->assertOk();
        $this->assertSame('cancelled', SalesOrder::query()->findOrFail($cart)->status->value);

        // Still blocked: GET pages redirect home, writes return the Sandbox guard.
        foreach (['/finance', '/inventory/warehouses', '/inventory/transfer-requests', '/catalog/products', '/integrations/woocommerce'] as $url) {
            $this->get($url)->assertRedirect('/test-mode');
        }
        foreach (['/sales/orders/1/out-of-stock-articles',
            '/procurement/suppliers', '/inventory/transfer-requests', '/integrations/woocommerce', '/inventory/opening-stock'] as $url) {
            $this->postJson($url, [])->assertForbidden()->assertJsonPath('code', 'SANDBOX_DOMAIN_UNAVAILABLE');
        }

        // Production is unchanged after leaving.
        $this->post('/test-mode/leave')->assertRedirect();
        foreach ($production as $url) {
            $this->get($url)->assertOk();
        }
        $this->postJson('/sales/orders/999999/fulfill')->assertNotFound();
    }
}
