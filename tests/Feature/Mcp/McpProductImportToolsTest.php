<?php

namespace Tests\Feature\Mcp;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\McpConnection;
use App\Models\McpOperation;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Products\ProductOperationTools;
use Carbon\CarbonImmutable;
use Tests\Support\CatalogTestCase;

/** Phase A: MCP product catalog import / merge through the product-operation engine. */
class McpProductImportToolsTest extends CatalogTestCase
{
    private const SCOPES = ['products.view', 'products.import', 'products.update'];

    private User $owner;

    private Organization $organization;

    private TaxRate $tax;

    private string $connectionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
        config(['mcp.operations.prepare_per_minute' => 1000, 'mcp.operations.execute_per_minute' => 1000]);

        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->organization->settings = ['allow_mcp_connections' => true];
        $this->organization->save();
        $this->organization->refresh();
        $this->tax = $this->createTaxRate($this->organization, 'TVA 20', '20.0000');
        $this->connect($this->owner);
    }

    public function test_preview_classifies_new_rows_as_create_without_touching_the_catalog(): void
    {
        $preview = $this->preview([$this->row('IMP-A', ['row_number' => 2])], 'first');

        $this->assertTrue($preview['ok'], json_encode($preview));
        $data = $preview['data'];
        $this->assertSame('previewed', $data['status']);
        $this->assertSame(['total_rows' => 1, 'to_create' => 1, 'to_update' => 0, 'unchanged' => 0, 'conflicts' => 0, 'invalid' => 0, 'duplicate_rows' => 0, 'result' => null], $data['import']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $data['preview']['preview_fingerprint']);
        $row = $data['rows']['items'][0];
        $this->assertSame(['create', 2, null], [$row['action'], $row['row_number'], $row['resolved_target']]);
        $this->assertSame('100.0000', $row['after']['variant.public_price_ttc']);
        $this->assertSame('Produit IMP-A', $row['after']['name']);
        $this->assertSame(0, Product::query()->where('organization_id', $this->organization->id)->count(), 'Preview never mutates the catalog.');
    }

    public function test_reimporting_a_catalog_merges_it_instead_of_duplicating(): void
    {
        // First file: A and B are created.
        $first = $this->preview([$this->row('IMP-A'), $this->row('IMP-B')], 'catalog-v1');
        $this->assertSame(2, $first['data']['import']['to_create']);
        $done = $this->execute($first);
        $this->assertSame('completed', $done['status'], json_encode($done));
        $this->assertSame(2, $done['import']['result']['created']);
        $this->assertSame(2, ProductVariant::query()->where('organization_id', $this->organization->id)->count());
        $a = $this->variant('IMP-A');
        $updatedAtA = $a->updated_at->toIso8601String();

        // Later: A unchanged, B price changed, C new.
        $this->travel(5)->minutes();
        $second = $this->preview([
            $this->row('IMP-A'),
            $this->row('IMP-B', ['variant' => ['public_price_ttc' => '120.5000']]),
            $this->row('IMP-C'),
        ], 'catalog-v2');
        $import = $second['data']['import'];
        $this->assertSame([1, 1, 1], [$import['to_create'], $import['to_update'], $import['unchanged']], json_encode($second));

        $rows = collect($second['data']['rows']['items'])->keyBy(fn ($row) => $row['identifiers']['sku']);
        $this->assertSame('unchanged', $rows['IMP-A']['action']);
        $this->assertSame($a->id, $rows['IMP-A']['resolved_target']['variant_id']);
        $this->assertSame('update', $rows['IMP-B']['action']);
        $this->assertSame(['variant.public_price_ttc'], $rows['IMP-B']['changed_fields'], 'Only changed fields are reported.');
        $this->assertSame(['variant.public_price_ttc' => '100.0000'], $rows['IMP-B']['before']);
        $this->assertSame(['variant.public_price_ttc' => '120.5000'], $rows['IMP-B']['after']);
        $this->assertSame('create', $rows['IMP-C']['action']);

        $done = $this->execute($second);
        $this->assertSame('completed', $done['status']);
        $this->assertSame(['created' => 1, 'updated' => 1, 'failed' => 0, 'pending' => 0, 'skipped' => 1, 'conflicts_not_executed' => 0, 'invalid_not_executed' => 0], $done['import']['result']);

        $this->assertSame(3, ProductVariant::query()->where('organization_id', $this->organization->id)->count(), 'No duplicate A/B.');
        $this->assertSame(3, Product::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame('120.5000', $this->variant('IMP-B')->public_price_ttc);
        $this->assertSame($updatedAtA, $this->variant('IMP-A')->updated_at->toIso8601String(), 'Unchanged rows are not written.');

        // Canonical audit events, attributed to the MCP connection.
        $updated = AuditLog::query()->where('event', 'product_variant.updated')->where('auditable_id', $this->variant('IMP-B')->id)->sole();
        $this->assertSame(['mcp', $this->connectionId, $this->owner->id], [$updated->actor_channel, (string) $updated->mcp_connection_id, $updated->actor_id]);
        $this->assertSame(3, AuditLog::query()->where('event', 'product.created')->where('actor_channel', 'mcp')->count());
    }

    public function test_product_level_fields_are_merged_and_unchanged_fields_are_omitted_from_the_diff(): void
    {
        $brand = $this->createBrand($this->organization, 'Shure');
        $product = $this->createProduct($this->organization, 'Micro', 'IMP-MIC', ['public_price_ttc' => '100.0000', 'tax_rate_id' => $this->tax->id, 'purchase_price' => '40.0000']);

        $preview = $this->preview([['name' => 'Micro', 'description' => 'Micro dynamique', 'brand_id' => $brand->id,
            'variant' => ['sku' => 'IMP-MIC', 'public_price_ttc' => '100.0000', 'barcode' => '3700000000001']]], 'merge-product');
        $row = $preview['data']['rows']['items'][0];
        $this->assertSame('update', $row['action']);
        $this->assertEqualsCanonicalizing(['description', 'brand_id', 'variant.barcode'], $row['changed_fields']);
        $this->assertEquals(['product_id' => $product->id, 'variant_id' => $product->variants->first()->id], $row['resolved_target']);

        $this->assertSame('completed', $this->execute($preview)['status']);
        $product->refresh();
        $variant = $product->variants()->sole();
        $this->assertSame(['Micro dynamique', $brand->id, '3700000000001'], [$product->description, $product->brand_id, $variant->barcode]);
        $this->assertSame('40.0000', $variant->purchase_price, 'The import never touches purchase costs.');
    }

    public function test_identifier_conflicts_are_surfaced_and_excluded_from_execution(): void
    {
        $this->createProduct($this->organization, 'Existant 1', 'EX-1', ['barcode' => 'BAR-1', 'reference' => 'REF-1', 'public_price_ttc' => '10.0000']);
        $this->createProduct($this->organization, 'Existant 2', 'EX-2', ['barcode' => 'BAR-2', 'reference' => 'REF-2', 'public_price_ttc' => '10.0000']);

        $preview = $this->preview([
            $this->row('EX-1', ['variant' => ['barcode' => 'BAR-2']]),            // SKU of variant 1, barcode of variant 2
            $this->row('NEW-1', ['variant' => ['barcode' => 'BAR-1']]),           // new SKU, barcode of an existing variant
            $this->row('NEW-2', ['variant' => ['reference' => 'REF-2']]),         // new SKU, reference of an existing variant
            $this->row('DUP', ['name' => 'Version 1']),                           // same SKU twice with different data
            $this->row('DUP', ['name' => 'Version 2']),
            $this->row('SAME', ['variant' => ['barcode' => 'SHARED']]),           // two new SKUs claiming one barcode
            $this->row('OTHER', ['variant' => ['barcode' => 'SHARED']]),
            $this->row('TWICE'), $this->row('TWICE'),                             // identical rows collapse
            $this->row('OK-1'),
        ], 'conflicts');

        $rows = collect($preview['data']['rows']['items']);
        $codes = $rows->map(fn ($row) => [$row['action'], $row['issues'][0]['code'] ?? null])->all();
        $this->assertSame([
            ['conflict', 'duplicate_identifier'],
            ['conflict', 'ambiguous_match'],
            ['conflict', 'ambiguous_match'],
            ['conflict', 'duplicate_identifier_in_request'],
            ['conflict', 'duplicate_identifier_in_request'],
            ['conflict', 'duplicate_identifier_in_request'],
            ['conflict', 'duplicate_identifier_in_request'],
            ['create', null],
            ['skipped', 'duplicate_row'],
            ['create', null],
        ], $codes);
        $this->assertSame(['variant.barcode'], $rows[0]['issues'][0]['fields']);
        $this->assertSame(7, $preview['data']['import']['conflicts']);

        $done = $this->execute($preview);
        $this->assertSame(2, $done['import']['result']['created']);
        $this->assertSame(['EX-1', 'EX-2', 'OK-1', 'TWICE'], ProductVariant::query()->where('organization_id', $this->organization->id)->orderBy('sku')->pluck('sku')->all());
        $this->assertSame('BAR-1', $this->variant('EX-1')->barcode, 'Conflicting rows are never applied.');
    }

    public function test_invalid_rows_are_reported_per_row(): void
    {
        $preview = $this->preview([
            ['variant' => ['sku' => 'NO-NAME', 'public_price_ttc' => '10.0000']],
            ['name' => 'Sans SKU', 'variant' => ['public_price_ttc' => '10.0000']],
            $this->row('NUM', ['variant' => ['public_price_ttc' => 10.5]]),
            $this->row('TXT', ['variant' => ['public_price_ttc' => '10,50 €']]),
            $this->row('LOCAL', ['image_url' => 'C:\\images\\product.jpg']),
            $this->row('FTP', ['variant' => ['image_url' => 'ftp://example.com/a.jpg']]),
            $this->row('COST', ['variant' => ['purchase_price' => '5.0000']]),
            $this->row('EXTRA', ['stock_quantity' => '3']),
            $this->row('NOPRICE', ['variant' => ['public_price_ttc' => null]]),
            $this->row('IMG', ['image_url' => 'https://cdn.example.com/a.jpg']),
        ], 'invalid');

        $rows = collect($preview['data']['rows']['items']);
        $this->assertSame([
            ['invalid', 'validation_failed'],
            ['invalid', 'missing_identifier'],
            ['invalid', 'invalid_decimal'],
            ['invalid', 'invalid_decimal'],
            ['invalid', 'invalid_image_url'],
            ['invalid', 'invalid_image_url'],
            ['invalid', 'unsupported_field'],
            ['invalid', 'unsupported_field'],
            ['invalid', 'validation_failed'],
            ['create', null],
        ], $rows->map(fn ($row) => [$row['action'], $row['issues'][0]['code'] ?? null])->all());
        $this->assertSame(['variant.purchase_price'], $rows[6]['issues'][0]['fields']);
        $this->assertStringContainsString('prepare_purchase_price_bulk_update', $rows[6]['issues'][0]['message']);
        $this->assertSame(['image_url'], $rows[4]['issues'][0]['fields']);
        $this->assertSame(9, $preview['data']['import']['invalid']);
    }

    public function test_other_organizations_are_never_matched_and_their_taxonomy_is_rejected(): void
    {
        $otherOwner = User::factory()->create();
        $other = $this->createOrganization($otherOwner, 'Other');
        $other->settings = ['allow_mcp_connections' => true];
        $other->save();
        $foreign = $this->createProduct($other, 'Produit étranger', 'SHARED-SKU', ['public_price_ttc' => '999.0000']);
        $foreignBrand = $this->createBrand($other, 'Foreign brand');
        $foreignTax = $this->createTaxRate($other, 'Foreign', '10.0000');

        $preview = $this->preview([
            $this->row('SHARED-SKU'),
            $this->row('X-BRAND', ['brand_id' => $foreignBrand->id]),
            $this->row('X-TAX', ['variant' => ['tax_rate_id' => $foreignTax->id]]),
        ], 'tenant');

        $rows = collect($preview['data']['rows']['items']);
        $this->assertSame('create', $rows[0]['action'], 'Another organization\'s SKU is invisible.');
        $this->assertSame(['invalid', 'reference_not_found'], [$rows[1]['action'], $rows[1]['issues'][0]['code']]);
        $this->assertSame(['invalid', 'reference_not_found'], [$rows[2]['action'], $rows[2]['issues'][0]['code']]);

        $this->execute($preview);
        $this->assertSame('999.0000', $foreign->variants()->sole()->public_price_ttc);
        $this->assertSame(1, ProductVariant::query()->where('organization_id', $this->organization->id)->where('sku', 'SHARED-SKU')->count());
        $this->assertSame(1, ProductVariant::query()->where('organization_id', $other->id)->count());

        // An operation of another connection/organization is not found.
        $operationId = $preview['data']['operation_id'];
        app(McpExecutionContext::class)->reset();
        $this->connect($otherOwner, self::SCOPES, $other->refresh());
        $this->assertSame('MCP_ENTITY_NOT_FOUND', $this->tool('getProductImport', ['operation_id' => $operationId])['error']['code']);
    }

    public function test_scope_and_erp_permission_are_both_required(): void
    {
        // Scope missing.
        $this->connect($this->owner, ['products.view', 'products.update']);
        $this->assertSame('MCP_FORBIDDEN', $this->preview([$this->row('P-1')], 'no-scope')['error']['code']);

        // Scope granted, ERP permission missing.
        $member = User::factory()->create();
        $this->addOrganizationMember($this->organization, $member, ['products.view', 'products.update']);
        $this->connect($member, self::SCOPES);
        $this->assertSame('MCP_FORBIDDEN', $this->preview([$this->row('P-1')], 'no-permission')['error']['code']);

        // Import without products.update: creates are fine, updates are conflicts.
        $this->createProduct($this->organization, 'Existant', 'P-EXIST', ['public_price_ttc' => '10.0000']);
        $importer = User::factory()->create();
        $this->addOrganizationMember($this->organization, $importer, ['products.view', 'products.import']);
        $this->connect($importer, ['products.view', 'products.import']);
        $preview = $this->preview([$this->row('P-EXIST', ['variant' => ['public_price_ttc' => '20.0000']]), $this->row('P-NEW')], 'import-only');
        $rows = collect($preview['data']['rows']['items']);
        $this->assertSame(['conflict', 'update_not_allowed'], [$rows[0]['action'], $rows[0]['issues'][0]['code']]);
        $this->assertSame('create', $rows[1]['action']);
        $this->assertSame(['products.import'], McpOperation::query()->findOrFail($preview['data']['operation_id'])->required_scopes);
        $this->assertSame('completed', $this->execute($preview)['status']);
        $this->assertSame('10.0000', $this->variant('P-EXIST')->public_price_ttc);

        $this->assertSame(0, Product::query()->where('organization_id', $this->organization->id)->where('name', 'Produit P-1')->count());
    }

    public function test_preview_is_idempotent_and_a_changed_payload_with_the_same_key_conflicts(): void
    {
        $rows = [$this->row('IDEM-1'), $this->row('IDEM-2')];
        $first = $this->preview($rows, 'idem-key');
        $replay = $this->preview($rows, 'idem-key');

        $this->assertFalse($first['data']['idempotent_replay']);
        $this->assertTrue($replay['data']['idempotent_replay']);
        $this->assertSame($first['data']['operation_id'], $replay['data']['operation_id']);
        $this->assertSame($first['data']['preview']['preview_fingerprint'], $replay['data']['preview']['preview_fingerprint']);
        $this->assertSame(1, McpOperation::query()->where('type', McpOperation::TYPE_PRODUCT_IMPORT)->count());

        $changed = $this->preview([$this->row('IDEM-1', ['name' => 'Autre'])], 'idem-key');
        $this->assertSame('MCP_IDEMPOTENCY_CONFLICT', $changed['error']['code']);
    }

    public function test_a_stale_preview_is_rejected_instead_of_overwriting(): void
    {
        $this->createProduct($this->organization, 'Existant', 'STALE-1', ['public_price_ttc' => '10.0000']);
        $preview = $this->preview([$this->row('STALE-1', ['variant' => ['public_price_ttc' => '20.0000']])], 'stale-update');

        $this->travel(1)->minutes();
        $variant = $this->variant('STALE-1');
        $variant->public_price_ttc = '15.0000';
        $variant->save();

        $result = $this->tool('executeProductImport', ['operation_id' => $preview['data']['operation_id'], 'preview_fingerprint' => $preview['data']['preview']['preview_fingerprint']]);
        $this->assertSame('MCP_PREVIEW_STALE', $result['error']['code']);
        $this->assertSame('15.0000', $this->variant('STALE-1')->public_price_ttc);

        // A create whose SKU was taken after the preview is stale too.
        $create = $this->preview([$this->row('LATE')], 'stale-create');
        $this->createProduct($this->organization, 'Créé ailleurs', 'LATE', ['public_price_ttc' => '10.0000']);
        $result = $this->tool('executeProductImport', ['operation_id' => $create['data']['operation_id'], 'preview_fingerprint' => $create['data']['preview']['preview_fingerprint']]);
        $this->assertSame('MCP_PREVIEW_STALE', $result['error']['code']);
        $this->assertSame(1, ProductVariant::query()->where('sku', 'LATE')->count());
    }

    public function test_repeating_execution_never_applies_twice(): void
    {
        $preview = $this->preview([$this->row('ONCE')], 'once');
        $args = ['operation_id' => $preview['data']['operation_id'], 'preview_fingerprint' => $preview['data']['preview']['preview_fingerprint']];

        $this->assertTrue($this->tool('executeProductImport', $args)['ok']);
        $this->reconnect();
        $again = $this->tool('executeProductImport', $args);
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['data']['idempotent_replay']);
        $this->assertSame(1, ProductVariant::query()->where('sku', 'ONCE')->count());

        // Re-previewing the same file under a new key now finds the product: unchanged, nothing to execute.
        $this->reconnect();
        $replay = $this->preview([$this->row('ONCE')], 'once-again');
        $this->assertSame(1, $replay['data']['import']['unchanged']);
        $this->assertNull($replay['data']['preview']['preview_fingerprint']);
    }

    public function test_independent_rows_succeed_when_another_fails_during_execution(): void
    {
        $brand = $this->createBrand($this->organization, 'Éphémère');
        $preview = $this->preview([$this->row('PART-1', ['brand_id' => $brand->id]), $this->row('PART-2')], 'partial');
        $this->assertSame(2, $preview['data']['import']['to_create']);

        Brand::query()->whereKey($brand->id)->delete();
        $done = $this->execute($preview);

        $this->assertSame('partial', $done['status']);
        $this->assertSame(1, $done['import']['result']['created']);
        $this->assertSame(1, $done['import']['result']['failed']);
        $this->assertSame(['PART-2'], ProductVariant::query()->where('organization_id', $this->organization->id)->pluck('sku')->all());
        $failed = $this->tool('getProductImportItems', ['operation_id' => $preview['data']['operation_id'], 'action' => 'failed'])['data']['items'];
        $this->assertSame([['PART-1', 'stale_target']], array_map(fn ($row) => [$row['identifiers']['sku'], $row['error']['code']], $failed));
    }

    public function test_large_imports_validate_on_the_queue_and_rows_are_paginated(): void
    {
        config(['mcp.operations.sync_validation_max_items' => 50]);
        $rows = array_map(fn ($index) => $this->row(sprintf('BULK-%03d', $index), ['row_number' => $index + 1]), range(1, 120));

        $preview = $this->preview($rows, 'bulk');
        $this->assertTrue($preview['ok'], json_encode($preview));
        $this->reconnect();

        $import = $this->tool('getProductImport', ['operation_id' => $preview['data']['operation_id']])['data'];
        $this->assertSame('previewed', $import['status']);
        $this->assertSame(120, $import['import']['to_create']);

        $page = $this->tool('getProductImportItems', ['operation_id' => $import['operation_id'], 'page' => 3, 'per_page' => 50, 'action' => 'create'])['data'];
        $this->assertSame([120, false, 20], [$page['total'], $page['has_more'], count($page['items'])]);
        $this->assertSame(102, $page['items'][0]['row_number']);

        $done = $this->execute(['data' => $import]);
        $this->assertSame(120, $done['import']['result']['created']);
        $this->assertSame(120, ProductVariant::query()->where('organization_id', $this->organization->id)->count());
    }

    public function test_import_tools_are_listed_only_with_the_import_scope_and_bulk_tools_are_kept(): void
    {
        $listed = $this->listTools($this->owner, self::SCOPES);
        foreach (['preview_product_import', 'execute_product_import', 'get_product_import', 'get_product_import_items', 'prepare_product_bulk_update', 'execute_product_operation'] as $tool) {
            $this->assertContains($tool, $listed);
        }
        $this->assertNotContains('preview_product_import', $this->listTools($this->owner, ['products.view', 'products.update']));
    }

    public function test_retention_purge_keeps_the_durable_summary_without_inventing_counts(): void
    {
        $preview = $this->preview([$this->row('KEEP-1'), $this->row('KEEP-2')], 'retention');
        $this->execute($preview);

        $this->travel(31)->days();
        $this->artisan('mcp:prune-operations')->assertSuccessful();
        $this->reconnect();

        $import = $this->tool('getProductImport', ['operation_id' => $preview['data']['operation_id']])['data'];
        $this->assertSame(['completed', false, 2], [$import['status'], $import['details_available'], $import['progress']['succeeded']]);
        $this->assertNull($import['import']['to_create'], 'The create/update split is unknown once rows are purged, not 0.');
        $this->assertNull($import['import']['result']['created']);
        $this->assertSame('MCP_OPERATION_DETAILS_EXPIRED', $this->tool('getProductImportItems', ['operation_id' => $import['operation_id']])['error']['code']);
    }

    public function test_existing_bulk_update_still_previews_and_executes_through_the_shared_engine(): void
    {
        $this->createProduct($this->organization, 'Bulk', 'BULK-UPD', ['public_price_ttc' => '10.0000']);
        $prepared = $this->tool('prepareProductBulkUpdate', ['rows' => [['target_type' => 'variant', 'sku' => 'BULK-UPD', 'changes' => ['public_price_ttc' => '11.0000']]], 'idempotency_key' => 'bulk-upd']);
        $this->assertSame(1, $prepared['data']['counts']['changed'], json_encode($prepared));
        $this->assertArrayNotHasKey('import', $prepared['data']);
        $this->assertSame('MCP_ENTITY_NOT_FOUND', $this->tool('getProductImport', ['operation_id' => $prepared['data']['operation_id']])['error']['code'], 'Import tools only see import operations.');

        $this->assertTrue($this->tool('executeProductOperation', ['operation_id' => $prepared['data']['operation_id'], 'preview_fingerprint' => $prepared['data']['preview']['preview_fingerprint']])['ok']);
        $this->reconnect();
        $this->assertSame('completed', $this->tool('getProductOperation', ['operation_id' => $prepared['data']['operation_id']])['data']['status']);
        $this->assertSame('11.0000', $this->variant('BULK-UPD')->public_price_ttc);
    }

    /** @return array<string, mixed> */
    private function row(string $sku, array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Produit '.$sku, 'status' => 'active',
            'variant' => ['sku' => $sku, 'public_price_ttc' => '100.0000', 'tax_rate_id' => $this->tax->id],
        ], $overrides);
    }

    /** @param list<mixed> $rows @return array<string, mixed> */
    private function preview(array $rows, string $key): array
    {
        return $this->tool('previewProductImport', ['rows' => $rows, 'idempotency_key' => $key]);
    }

    /** Executes with the preview fingerprint, then returns the import as seen afterwards. @return array<string, mixed> */
    private function execute(array $preview): array
    {
        $result = $this->tool('executeProductImport', ['operation_id' => $preview['data']['operation_id'], 'preview_fingerprint' => $preview['data']['preview']['preview_fingerprint']]);
        $this->assertTrue($result['ok'], json_encode($result));
        // The synchronous test queue runs the chunks inline and releases the connection binding.
        $this->reconnect();

        return $this->tool('getProductImport', ['operation_id' => $preview['data']['operation_id']])['data'];
    }

    private function variant(string $sku): ProductVariant
    {
        return ProductVariant::query()->where('organization_id', $this->organization->id)->where('sku', $sku)->sole();
    }

    /** @param list<string> $scopes */
    private function connect(User $user, array $scopes = self::SCOPES, ?Organization $organization = null): void
    {
        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($user, $organization ?? $this->organization, 'Agent', $scopes, null);
        $this->connectionId = (string) $issued['connection']->getKey();
        app(McpExecutionContext::class)->bind($issued['connection']->load(['organization', 'user', 'scopeRecords']));
    }

    private function reconnect(): void
    {
        $context = app(McpExecutionContext::class);
        $context->reset();
        $context->bind(McpConnection::query()->with(['organization', 'user', 'scopeRecords'])->findOrFail($this->connectionId));
    }

    /** @return array<string, mixed> */
    private function tool(string $method, array $arguments = []): array
    {
        return app(ProductOperationTools::class)->{$method}(...$arguments)->structuredContent;
    }

    /** @param list<string> $scopes @return list<string> */
    private function listTools(User $user, array $scopes): array
    {
        // The HTTP middleware binds its own connection.
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
