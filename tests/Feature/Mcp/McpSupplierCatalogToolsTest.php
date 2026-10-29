<?php

namespace Tests\Feature\Mcp;

use App\Actions\Procurement\SaveProductVariantSupplierAction;
use App\Models\AuditLog;
use App\Models\McpIdempotencyRecord;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\ProductVariantSupplier;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Procurement\SupplierCatalogTools;
use Carbon\CarbonImmutable;
use Tests\Support\CatalogTestCase;

/** Phase B1: supplier catalog relations over MCP. */
class McpSupplierCatalogToolsTest extends CatalogTestCase
{
    private const SCOPES = ['supplier_catalog.view', 'supplier_catalog.manage', 'product_cost.view'];

    private const TOOLS = ['get_variant_suppliers', 'get_supplier_products', 'preview_supplier_product_relation', 'save_supplier_product_relation'];

    private User $owner;

    private Organization $organization;

    private ProductVariant $xlr;

    private ProductVariant $shure;

    private Supplier $mifa;

    private Supplier $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->organization->settings = ['allow_mcp_connections' => true];
        $this->organization->save();
        $this->organization->refresh();
        $this->xlr = $this->createProduct($this->organization, 'Cordial XLR', 'COR-XLR')->variants->first();
        $this->shure = $this->createProduct($this->organization, 'Shure MV7X', 'SHU-MV7X')->variants->first();
        $this->mifa = $this->supplier($this->organization, 'MIFA');
        $this->other = $this->supplier($this->organization, 'Autre fournisseur');
        $this->connect($this->owner);
    }

    public function test_link_mifa_to_the_xlr_at_120_mad_two_days_preferred_and_read_it_back(): void
    {
        $relation = ['purchase_price' => '120.0000', 'lead_time_days' => 2, 'is_preferred' => true];
        $preview = $this->preview($this->mifa, $this->xlr, $relation);
        $this->assertTrue($preview['valid'], json_encode($preview));
        $this->assertSame(['create', null], [$preview['mode'], $preview['current']]);
        $this->assertSame(['supplier_id' => $this->mifa->id, 'supplier_name' => 'MIFA'], $preview['preferred_change']['new_preferred']);
        $this->assertNull($preview['preferred_change']['will_unset']);
        $this->assertSame(0, ProductVariantSupplier::query()->count(), 'Preview never writes.');

        $saved = $this->saveRelation($this->mifa, $this->xlr, $relation, $preview['preview_fingerprint'], 'link-mifa-xlr');
        $this->assertTrue($saved['ok'], json_encode($saved));
        $this->assertSame(['120.0000', 'MAD', 2, true], [$saved['data']['relation']['purchase_price'], $saved['data']['relation']['currency_code'], $saved['data']['relation']['lead_time_days'], $saved['data']['relation']['is_preferred']]);

        $read = $this->tool('getVariantSuppliers', ['sku' => 'COR-XLR'])['data'];
        $this->assertSame('MIFA', $read['preferred_supplier']['name']);
        $this->assertSame(['MIFA'], array_column(array_column($read['suppliers'], 'supplier'), 'name'));

        $products = $this->tool('getSupplierProducts', ['supplier_id' => $this->mifa->id])['data'];
        $this->assertSame([1, 'COR-XLR'], [$products['total'], $products['products'][0]['variant']['sku']]);

        $audit = AuditLog::query()->where('event', 'product_variant_supplier.created')->sole();
        $this->assertSame(['mcp', $this->owner->id], [$audit->actor_channel, $audit->actor_id]);
    }

    public function test_update_and_preferred_replacement_are_previewed_explicitly(): void
    {
        $this->link($this->mifa, $this->xlr, ['is_preferred' => true, 'purchase_price' => '120.0000']);
        $this->link($this->other, $this->xlr, ['purchase_price' => '125.0000']);

        $update = $this->preview($this->mifa, $this->xlr, ['purchase_price' => '118.0000']);
        $this->assertSame(['update', ['purchase_price']], [$update['mode'], $update['changed_fields']]);
        $this->assertSame('120.0000', $update['current']['purchase_price']);
        $this->assertTrue($this->saveRelation($this->mifa, $this->xlr, ['purchase_price' => '118.0000'], $update['preview_fingerprint'], 'upd-1')['ok']);

        $swap = $this->preview($this->other, $this->xlr, ['is_preferred' => true]);
        $this->assertSame('MIFA', $swap['preferred_change']['current_preferred']['supplier_name']);
        $this->assertSame('Autre fournisseur', $swap['preferred_change']['new_preferred']['supplier_name']);
        $this->assertSame('MIFA', $swap['preferred_change']['will_unset']['supplier_name']);
        $this->assertTrue($this->saveRelation($this->other, $this->xlr, ['is_preferred' => true], $swap['preview_fingerprint'], 'swap-1')['ok']);
        $this->assertSame([$this->other->id], ProductVariantSupplier::query()->where('is_preferred', true)->pluck('supplier_id')->all());

        $unchanged = $this->preview($this->other, $this->xlr, ['is_preferred' => true]);
        $this->assertSame([false, null], [$unchanged['has_changes'], $unchanged['preview_fingerprint']]);
    }

    public function test_duplicates_are_impossible_and_a_deactivated_link_is_reactivated(): void
    {
        $first = $this->preview($this->mifa, $this->xlr, ['lead_time_days' => 3]);
        $second = $this->preview($this->mifa, $this->xlr, ['lead_time_days' => 3]);
        $this->assertTrue($this->saveRelation($this->mifa, $this->xlr, ['lead_time_days' => 3], $first['preview_fingerprint'], 'dup-a')['ok']);
        // A second create preview, saved under another key, is stale: the pair now exists.
        $this->assertSame('MCP_PREVIEW_STALE', $this->saveRelation($this->mifa, $this->xlr, ['lead_time_days' => 3], $second['preview_fingerprint'], 'dup-b')['error']['code']);
        $this->assertSame(1, ProductVariantSupplier::query()->count());

        $deactivate = $this->preview($this->mifa, $this->xlr, ['status' => 'inactive']);
        $this->saveRelation($this->mifa, $this->xlr, ['status' => 'inactive'], $deactivate['preview_fingerprint'], 'off');
        $relink = $this->preview($this->mifa, $this->xlr, ['lead_time_days' => 4]);
        $this->assertSame('reactivate', $relink['mode']);
        $this->assertTrue($this->saveRelation($this->mifa, $this->xlr, ['lead_time_days' => 4], $relink['preview_fingerprint'], 'on')['ok']);
        $this->assertSame([1, 'active', 4], [ProductVariantSupplier::query()->count(), ProductVariantSupplier::query()->sole()->status, ProductVariantSupplier::query()->sole()->lead_time_days]);
    }

    public function test_a_stale_preview_is_rejected_and_an_idempotent_retry_replays(): void
    {
        $this->link($this->mifa, $this->xlr, ['is_preferred' => true]);
        $this->link($this->other, $this->xlr);
        $swap = $this->preview($this->other, $this->xlr, ['is_preferred' => true]);

        // The preferred supplier changed after the preview (e.g. deactivated on the web): what the preview said would be unset is no longer true.
        $this->travel(1)->minutes();
        app(SaveProductVariantSupplierAction::class)->execute($this->owner, $this->organization, ['status' => 'inactive'], ProductVariantSupplier::query()->where('supplier_id', $this->mifa->id)->sole());
        $this->assertSame('MCP_PREVIEW_STALE', $this->saveRelation($this->other, $this->xlr, ['is_preferred' => true], $swap['preview_fingerprint'], 'stale')['error']['code']);
        $this->assertSame(0, ProductVariantSupplier::query()->where('is_preferred', true)->count());

        // The relation itself changed after an update preview.
        $edit = $this->preview($this->other, $this->xlr, ['lead_time_days' => 5]);
        $this->travel(1)->minutes();
        app(SaveProductVariantSupplierAction::class)->execute($this->owner, $this->organization, ['lead_time_days' => 7], ProductVariantSupplier::query()->where('supplier_id', $this->other->id)->sole());
        $this->assertSame('MCP_PREVIEW_STALE', $this->saveRelation($this->other, $this->xlr, ['lead_time_days' => 5], $edit['preview_fingerprint'], 'stale-2')['error']['code']);
        $this->assertSame(7, ProductVariantSupplier::query()->where('supplier_id', $this->other->id)->sole()->lead_time_days);

        $preview = $this->preview($this->mifa, $this->shure, ['purchase_price' => '50.0000']);
        $first = $this->saveRelation($this->mifa, $this->shure, ['purchase_price' => '50.0000'], $preview['preview_fingerprint'], 'retry-key');
        $retry = $this->saveRelation($this->mifa, $this->shure, ['purchase_price' => '50.0000'], $preview['preview_fingerprint'], 'retry-key');
        $this->assertTrue($retry['ok'], json_encode($retry));
        $this->assertTrue($retry['data']['idempotent_replay']);
        $this->assertSame($first['data']['target'], $retry['data']['target']);
        $this->assertSame('MCP_IDEMPOTENCY_CONFLICT', $this->saveRelation($this->mifa, $this->shure, ['purchase_price' => '51.0000'], $preview['preview_fingerprint'], 'retry-key')['error']['code']);
        $this->assertSame(1, McpIdempotencyRecord::query()->where('tool', 'save_supplier_product_relation')->where('idempotency_key', 'retry-key')->count());
    }

    public function test_other_organizations_are_invisible_and_invalid_data_is_reported_per_field(): void
    {
        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Other');
        $foreignSupplier = $this->supplier($foreign, 'Foreign');
        $foreignVariant = $this->createProduct($foreign, 'Foreign', 'FOR-1')->variants->first();

        $this->assertSame('not_found', $this->preview($foreignSupplier, $this->xlr)['issues'][0]['code']);
        $this->assertSame('not_found', $this->preview($this->mifa, $foreignVariant)['issues'][0]['code']);
        $this->assertSame('MCP_VARIANT_NOT_FOUND', $this->tool('getVariantSuppliers', ['variant_id' => $foreignVariant->id])['error']['code']);
        $this->assertSame('MCP_SUPPLIER_NOT_FOUND', $this->tool('getSupplierProducts', ['supplier_id' => $foreignSupplier->id])['error']['code']);

        $invalid = $this->preview($this->mifa, $this->xlr, ['purchase_price' => '-1', 'lead_time_days' => -2]);
        $this->assertFalse($invalid['valid']);
        $this->assertEqualsCanonicalizing(['relation.purchase_price', 'relation.lead_time_days'], array_column($invalid['issues'], 'path'));
        $this->assertSame('MCP_INVALID_ARGUMENTS', $this->tool('previewSupplierProductRelation', ['supplier_id' => $this->mifa->id, 'product_variant_id' => $this->xlr->id, 'relation' => ['organization_id' => 1]])['error']['code']);
        $this->assertSame(0, ProductVariantSupplier::query()->count());
    }

    public function test_scope_and_erp_permission_are_both_required_and_prices_need_cost_visibility(): void
    {
        // Scope missing.
        $this->connect($this->owner, ['supplier_catalog.view']);
        $this->assertSame('MCP_FORBIDDEN', $this->tool('previewSupplierProductRelation', ['supplier_id' => $this->mifa->id, 'product_variant_id' => $this->xlr->id])['error']['code']);

        // Scope granted, ERP permission missing.
        $member = User::factory()->create();
        $this->addOrganizationMember($this->organization, $member, ['products.view', 'suppliers.view']);
        $this->connect($member, self::SCOPES);
        $this->assertTrue($this->tool('getVariantSuppliers', ['variant_id' => $this->xlr->id])['ok']);
        $this->assertSame('MCP_FORBIDDEN', $this->tool('previewSupplierProductRelation', ['supplier_id' => $this->mifa->id, 'product_variant_id' => $this->xlr->id])['error']['code']);

        // products.view is required to read relations, whatever the scope.
        $noCatalog = User::factory()->create();
        $this->addOrganizationMember($this->organization, $noCatalog, ['suppliers.view']);
        $this->connect($noCatalog, self::SCOPES);
        $this->assertSame('MCP_FORBIDDEN', $this->tool('getVariantSuppliers', ['variant_id' => $this->xlr->id])['error']['code']);

        // Without a cost/PO scope prices are hidden and cannot be set.
        $this->connect($this->owner, ['supplier_catalog.view', 'supplier_catalog.manage']);
        $this->link($this->mifa, $this->xlr, ['purchase_price' => '120.0000']);
        $read = $this->tool('getVariantSuppliers', ['variant_id' => $this->xlr->id])['data'];
        $this->assertSame([false, null], [$read['prices_visible'], $read['suppliers'][0]['purchase_price']]);
        $this->assertSame('MCP_FORBIDDEN', $this->tool('previewSupplierProductRelation', ['supplier_id' => $this->mifa->id, 'product_variant_id' => $this->xlr->id, 'relation' => ['purchase_price' => '1.0000']])['error']['code']);
        $this->assertArrayNotHasKey('purchase_price', $this->preview($this->mifa, $this->xlr, ['lead_time_days' => 1])['current']);
    }

    public function test_tools_are_listed_only_with_scope_and_permission(): void
    {
        $this->assertSame(self::TOOLS, array_values(array_intersect(self::TOOLS, $this->listTools($this->owner, self::SCOPES))));
        $this->assertSame(['get_variant_suppliers', 'get_supplier_products'], array_values(array_intersect(self::TOOLS, $this->listTools($this->owner, ['supplier_catalog.view']))));
        $this->assertSame([], array_values(array_intersect(self::TOOLS, $this->listTools($this->owner, ['products.view', 'suppliers.view']))));

        $viewer = User::factory()->create();
        $this->addOrganizationMember($this->organization, $viewer, ['products.view', 'suppliers.view']);
        $this->assertSame(['get_variant_suppliers', 'get_supplier_products'], array_values(array_intersect(self::TOOLS, $this->listTools($viewer, self::SCOPES))));
    }

    /* ------------------------------------------------------------------ helpers */

    /** @param array<string, mixed> $relation @return array<string, mixed> */
    private function preview(Supplier $supplier, ProductVariant $variant, array $relation = []): array
    {
        $result = $this->tool('previewSupplierProductRelation', ['supplier_id' => $supplier->id, 'product_variant_id' => $variant->id, 'relation' => $relation]);
        $this->assertTrue($result['ok'], json_encode($result));

        return $result['data'];
    }

    /** @param array<string, mixed> $relation @return array<string, mixed> */
    private function saveRelation(Supplier $supplier, ProductVariant $variant, array $relation, ?string $fingerprint, string $key): array
    {
        return $this->tool('saveSupplierProductRelation', ['supplier_id' => $supplier->id, 'product_variant_id' => $variant->id, 'relation' => $relation,
            'preview_fingerprint' => (string) $fingerprint, 'idempotency_key' => $key]);
    }

    /** Through the canonical action, bound to the MCP connection. @param array<string, mixed> $data */
    private function link(Supplier $supplier, ProductVariant $variant, array $data = []): ProductVariantSupplier
    {
        return app(SaveProductVariantSupplierAction::class)->execute($this->owner, $this->organization, ['supplier_id' => $supplier->id, 'product_variant_id' => $variant->id, ...$data]);
    }

    private function supplier(Organization $organization, string $name): Supplier
    {
        $supplier = new Supplier;
        $supplier->organization_id = $organization->id;
        $supplier->name = $name;
        $supplier->active = true;
        $supplier->save();

        return $supplier;
    }

    /** @param list<string> $scopes */
    private function connect(User $user, array $scopes = self::SCOPES): void
    {
        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($user, $this->organization, 'Agent', $scopes, null);
        app(McpExecutionContext::class)->bind($issued['connection']->load(['organization', 'user', 'scopeRecords']));
    }

    /** @return array<string, mixed> */
    private function tool(string $method, array $arguments = []): array
    {
        return app(SupplierCatalogTools::class)->{$method}(...$arguments)->structuredContent;
    }

    /** @param list<string> $scopes @return list<string> */
    private function listTools(User $user, array $scopes): array
    {
        app(McpExecutionContext::class)->reset();
        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($user, $this->organization, 'Agent', $scopes, null);
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
