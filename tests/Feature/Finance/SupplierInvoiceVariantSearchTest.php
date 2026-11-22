<?php

namespace Tests\Feature\Finance;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SupplierInvoiceTestCase;

/** Phase 2 Sprint 1 (PR5b): variant picker for supplier invoice goods lines. */
class SupplierInvoiceVariantSearchTest extends SupplierInvoiceTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        config(['supplier_invoices.enabled' => true]);
        [$this->owner, $this->org, $this->store] = $this->invoiceFixture();
    }

    private function search(string $q, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->getJson(route('finance.supplier-invoices.variant-search', ['q' => $q]));
    }

    /** @return list<string> */
    private function skus(string $q, ?User $as = null): array
    {
        return collect($this->search($q, $as)->assertOk()->json('data'))->pluck('sku')->sort()->values()->all();
    }

    public function test_it_matches_sku_reference_barcode_label_and_product_name_with_the_documented_shape(): void
    {
        $product = $this->createProduct($this->org, 'Micro-ondes Samsung', 'SKU-MW-1', ['reference' => 'REF-ALPHA', 'barcode' => '6111222333444', 'label' => 'Noir 23L', 'purchase_price' => '555.0000']);

        foreach (['SKU-MW', 'REF-ALPHA', '61112223', 'noir 23', 'samsung'] as $q) {
            $this->assertSame(['SKU-MW-1'], $this->skus($q), $q);
        }
        $row = $this->search('SKU-MW')->json('data.0');
        $this->assertSame(['id', 'label', 'product_name', 'sku'], collect($row)->keys()->sort()->values()->all());
        $this->assertSame($product->variants->first()->id, $row['id']);
        $this->assertSame('Micro-ondes Samsung', $row['product_name']);
        $this->assertSame('Noir 23L', $row['label']);
    }

    public function test_no_cost_or_price_field_is_ever_exposed(): void
    {
        $this->createProduct($this->org, 'Costly', 'SKU-COST', ['purchase_price' => '987.6543', 'default_sale_price' => '1234.5000']);

        $body = (string) $this->search('SKU-COST')->assertOk()->getContent();
        foreach (['purchase_price', 'price', '987.6543', '1234.5', 'cost'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, $needle);
        }
    }

    public function test_it_is_organization_scoped(): void
    {
        $this->createProduct($this->org, 'Mine', 'SKU-MINE');
        $foreignOwner = User::factory()->create();
        $foreignOrg = $this->createOrganization($foreignOwner, 'Foreign');
        $this->createProduct($foreignOrg, 'Theirs', 'SKU-THEIRS');

        $this->assertSame(['SKU-MINE'], $this->skus('SKU-'));
    }

    public function test_inactive_variants_and_inactive_products_are_excluded(): void
    {
        $active = $this->createProduct($this->org, 'Actif', 'SKU-OK-1');
        $inactiveVariant = $this->createProduct($this->org, 'Variante off', 'SKU-OK-2');
        $inactiveProduct = $this->createProduct($this->org, 'Produit off', 'SKU-OK-3');
        DB::table('product_variants')->where('id', $inactiveVariant->variants->first()->id)->update(['status' => 'inactive']);
        DB::table('products')->where('id', $inactiveProduct->id)->update(['status' => 'inactive']);

        $this->assertSame(['SKU-OK-1'], $this->skus('SKU-OK'));
        $this->assertNotNull($active);
    }

    public function test_minimum_length_and_limit(): void
    {
        $this->createProduct($this->org, 'Alpha', 'AB-001');
        $this->assertSame([], $this->search('A')->assertOk()->json('data'));
        $this->assertSame([], $this->search('  ')->assertOk()->json('data'));
        $this->assertSame([], $this->search('')->assertOk()->json('data'));
        $this->assertSame(['AB-001'], $this->skus('AB'));

        for ($i = 2; $i <= 25; $i++) {
            $this->createProduct($this->org, 'Bulk '.$i, sprintf('AB-%03d', $i));
        }
        $this->assertCount(20, $this->search('AB-')->json('data'));
    }

    public function test_like_wildcards_are_escaped(): void
    {
        $this->createProduct($this->org, 'Plain', 'SKU-PLAIN-1');
        $this->createProduct($this->org, 'Percent', 'SKU-50%-OFF');
        $this->createProduct($this->org, 'Underscore', 'SKU_UNDER');
        $this->createProduct($this->org, 'Bang', 'SKU!BANG');

        $this->assertSame([], $this->skus('%%'));
        $this->assertSame(['SKU-50%-OFF'], $this->skus('50%'));
        $this->assertSame(['SKU_UNDER'], $this->skus('SKU_U'));
        $this->assertSame([], $this->skus('SKU_P'), 'underscore is literal, not a one-char wildcard');
        $this->assertSame(['SKU!BANG'], $this->skus('U!B'));
    }

    public function test_permission_kill_switch_and_test_mode(): void
    {
        $this->createProduct($this->org, 'Alpha', 'AB-001');
        $viewer = User::factory()->create();
        $this->addOrganizationMember($this->org, $viewer, ['supplier_invoices.view'], roleName: 'V'.Str::random(4));
        $this->addStoreMember($this->store, $viewer);
        $this->activate($viewer, $this->org, $this->store);
        $this->search('AB-', $viewer)->assertForbidden();

        config(['supplier_invoices.enabled' => false]);
        $this->search('AB-')->assertNotFound();
        config(['supplier_invoices.enabled' => true]);

        $this->actingAs($this->owner)->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        $this->getJson(route('finance.supplier-invoices.variant-search', ['q' => 'AB-']))->assertStatus(403)->assertJsonPath('code', 'SANDBOX_DOMAIN_UNAVAILABLE');
        $this->post('/test-mode/leave')->assertRedirect();
        $this->get('/test-mode')->assertOk();
    }

    public function test_the_route_is_not_swallowed_by_the_show_wildcard(): void
    {
        $this->createProduct($this->org, 'Alpha', 'AB-001');

        $response = $this->search('AB-001')->assertOk();
        $this->assertSame('AB-001', $response->json('data.0.sku'));
        $this->assertStringEndsWith('/factures-fournisseurs/variant-search', parse_url(route('finance.supplier-invoices.variant-search'), PHP_URL_PATH));
        $this->assertSame('finance.supplier-invoices.variant-search', app('router')->getRoutes()->match(request()->create('/finance/factures-fournisseurs/variant-search', 'GET'))->getName());
    }
}
