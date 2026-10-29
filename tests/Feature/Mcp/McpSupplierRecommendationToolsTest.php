<?php

namespace Tests\Feature\Mcp;

use App\Actions\Procurement\SaveProductVariantSupplierAction;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Procurement\SupplierCatalogTools;
use App\Services\Mcp\Procurement\SupplierSourcingTools;
use Carbon\CarbonImmutable;
use Tests\Support\QuotationTestCase;

/** Phase B2: advisory supplier recommendations over MCP. */
class McpSupplierRecommendationToolsTest extends QuotationTestCase
{
    private const SCOPES = ['quotation_sourcing.view', 'quotation_sourcing.create', 'supplier_catalog.view', 'product_cost.view'];

    private User $owner;

    private Organization $organization;

    private Store $store;

    private ProductVariant $xlr;

    private Quotation $quotation;

    private QuotationLine $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->organization->settings = ['allow_mcp_connections' => true];
        $this->organization->save();
        $this->organization->refresh();
        $this->store = $this->createStore($this->organization, $this->owner);
        $this->createWarehouse($this->organization);
        $tax = $this->createTaxRate($this->organization, 'TVA 20', '20.0000');
        $this->xlr = $this->createProduct($this->organization, 'Cordial XLR', 'COR-XLR', ['default_sale_price' => '200.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($this->owner, $this->organization, $this->store);
        $this->quotation = $this->createQuotation($this->owner, $this->organization, $this->store);
        $this->line = $this->addCatalogQuotationLine($this->owner, $this->quotation, $this->xlr, ['quantity' => '2']);

        $this->link('MIFA', ['is_preferred' => true, 'lead_time_days' => 2, 'purchase_price' => '120.0000']);
        $this->link('Euro Parts', ['purchase_price' => '10.0000', 'currency_code' => 'EUR', 'lead_time_days' => 5]);
        $this->connect($this->owner);
    }

    public function test_recommendations_for_the_line_shortage_are_explained_and_flag_other_currencies(): void
    {
        $result = $this->recommend();
        $this->assertTrue($result['ok'], json_encode($result));
        $data = $result['data'];
        $this->assertSame(['2.0000', '2.0000', true], [$data['requested_quantity'], $data['shortage'], $data['recommendation_needed']]);

        [$mifa, $euro] = $data['recommendations'];
        $this->assertSame(['MIFA', 1, 'A', '120.0000'], [$mifa['supplier']['name'], $mifa['rank'], $mifa['tier'], $mifa['purchase_price']]);
        $this->assertSame(['preferred_supplier', 'fastest_lead_time'], array_column($mifa['reasons'], 'code'));
        $this->assertStringContainsString('it is the preferred supplier, it has the shortest known lead time (2 days)', $mifa['explanation']);
        $this->assertSame('Euro Parts', $euro['supplier']['name']);
        $this->assertContains('price_currency_not_comparable', array_column($euro['warnings'], 'code'));
        $this->assertStringContainsString('Advisory only', $data['note']);
        $this->assertSame(0, QuotationSupplierAvailabilityRequest::query()->count());
    }

    public function test_recommend_then_request_the_chosen_supplier_end_to_end(): void
    {
        $top = $this->recommend()['data']['recommendations'][0];
        $created = app(SupplierSourcingTools::class)->createSupplierAvailabilityRequest(
            quotation_id: $this->quotation->id, quotation_line_id: $this->line->id, supplier_id: $top['supplier']['id'], idempotency_key: 'ask-mifa',
        )->structuredContent;
        $this->assertTrue($created['ok'], json_encode($created));

        $request = QuotationSupplierAvailabilityRequest::query()->sole();
        $this->assertSame([$top['supplier']['id'], '2.0000'], [$request->supplier_id, $request->requested_quantity]);
        $after = $this->recommend()['data']['recommendations'][0];
        $this->assertSame([false, 'request_pending_for_line'], [$after['eligible_for_request'], $after['ineligible_reason']]);
    }

    public function test_reading_needs_no_manage_permission_but_needs_the_read_scopes_and_erp_permissions(): void
    {
        $seller = User::factory()->create();
        $this->addOrganizationMember($this->organization, $seller, ['quotations.view', 'products.view', 'suppliers.view']);
        $this->addStoreMember($this->store, $seller);
        $this->connect($seller, ['quotation_sourcing.view', 'supplier_catalog.view']);
        $read = $this->recommend()['data'];
        $this->assertSame('MIFA', $read['recommendations'][0]['supplier']['name']);
        $this->assertSame([false, null], [$read['prices_visible'], $read['recommendations'][0]['purchase_price']]);

        $this->connect($this->owner, ['supplier_catalog.view']);
        $this->assertSame('MCP_FORBIDDEN', $this->recommend()['error']['code']);
        $this->connect($this->owner, ['quotation_sourcing.view']);
        $this->assertSame('MCP_FORBIDDEN', $this->recommend()['error']['code']);
    }

    public function test_without_cost_visibility_prices_neither_rank_nor_leak(): void
    {
        app(McpExecutionContext::class)->reset();
        $this->link('Alpha', ['purchase_price' => '130.0000', 'lead_time_days' => 5]);
        $this->link('Bravo', ['purchase_price' => '120.0000', 'lead_time_days' => 5]);

        $this->connect($this->owner);
        $withCost = array_column(array_column($this->recommend()['data']['recommendations'], 'supplier'), 'name');
        $this->assertSame(['MIFA', 'Bravo', 'Alpha', 'Euro Parts'], $withCost, 'Same-currency price breaks the tie; EUR is not compared.');

        // Same user, no cost/purchase-order scope: prices are ignored for ranking and never returned.
        $this->connect($this->owner, ['quotation_sourcing.view', 'supplier_catalog.view']);
        $data = $this->recommend()['data'];
        $this->assertFalse($data['prices_visible']);
        $this->assertSame(['MIFA', 'Alpha', 'Bravo', 'Euro Parts'], array_column(array_column($data['recommendations'], 'supplier'), 'name'));
        foreach ($data['recommendations'] as $recommendation) {
            $this->assertNull($recommendation['purchase_price']);
            $codes = [...array_column($recommendation['reasons'], 'code'), ...array_column($recommendation['warnings'], 'code')];
            $this->assertSame([], array_values(array_intersect($codes, ['best_known_price', 'missing_price', 'price_currency_not_comparable'])));
        }
    }

    public function test_other_organizations_quotations_are_not_found(): void
    {
        app(McpExecutionContext::class)->reset();
        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Other');
        $foreign->settings = ['allow_mcp_connections' => true];
        $foreign->save();
        $foreignStore = $this->createStore($foreign, $foreignOwner);
        $this->activate($foreignOwner, $foreign->fresh(), $foreignStore);
        $foreignQuotation = $this->createQuotation($foreignOwner, $foreign->fresh(), $foreignStore);
        $foreignLine = $this->addCatalogQuotationLine($foreignOwner, $foreignQuotation, $this->createProduct($foreign, 'Foreign', 'FOR-1')->variants->first(), ['quantity' => '1']);

        $this->activate($this->owner, $this->organization, $this->store);
        $this->connect($this->owner);
        $this->assertSame('MCP_QUOTATION_NOT_FOUND', $this->recommend($foreignQuotation->id, $foreignLine->id)['error']['code']);
        $this->assertSame('MCP_QUOTATION_NOT_FOUND', $this->recommend($this->quotation->id, $foreignLine->id)['error']['code']);
    }

    public function test_the_tool_is_listed_only_with_both_read_scopes(): void
    {
        $this->assertContains('recommend_suppliers_for_quotation_line', $this->listTools(self::SCOPES));
        $this->assertNotContains('recommend_suppliers_for_quotation_line', $this->listTools(['supplier_catalog.view']));
        $this->assertNotContains('recommend_suppliers_for_quotation_line', $this->listTools(['quotation_sourcing.view']));
    }

    /** @return array<string, mixed> */
    private function recommend(?int $quotationId = null, ?int $lineId = null): array
    {
        return app(SupplierCatalogTools::class)->recommendSuppliersForQuotationLine($quotationId ?? $this->quotation->id, $lineId ?? $this->line->id)->structuredContent;
    }

    /** @param array<string, mixed> $data */
    private function link(string $name, array $data): void
    {
        $supplier = new Supplier;
        $supplier->forceFill(['organization_id' => $this->organization->id, 'name' => $name, 'active' => true])->save();
        app(SaveProductVariantSupplierAction::class)->execute($this->owner, $this->organization, ['supplier_id' => $supplier->id, 'product_variant_id' => $this->xlr->id, ...$data]);
    }

    /** @param list<string> $scopes */
    private function connect(User $user, array $scopes = self::SCOPES): void
    {
        app(McpExecutionContext::class)->reset();
        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($user, $this->organization, 'Agent', $scopes, null);
        app(McpExecutionContext::class)->bind($issued['connection']->load(['organization', 'user', 'scopeRecords']));
    }

    /** @param list<string> $scopes @return list<string> */
    private function listTools(array $scopes): array
    {
        app(McpExecutionContext::class)->reset();
        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($this->owner, $this->organization, 'Agent', $scopes, null);
        $headers = ['Authorization' => 'Bearer '.$issued['secret'], 'Accept' => 'application/json, text/event-stream'];
        $session = $this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]])->headers->get('Mcp-Session-Id');
        $headers += ['Mcp-Session-Id' => $session, 'Mcp-Protocol-Version' => '2025-11-25'];
        $this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $names = array_column($this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass])->json('result.tools') ?? [], 'name');
        $this->flushHeaders();

        return $names;
    }
}
