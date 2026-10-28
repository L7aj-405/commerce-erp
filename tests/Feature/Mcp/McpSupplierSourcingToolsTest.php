<?php

namespace Tests\Feature\Mcp;

use App\Actions\Quotations\IssueQuotationAction;
use App\Enums\QuotationStatus;
use App\Enums\QuotationSupplierAvailabilityStatus as Status;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\NonStockItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Procurement\SupplierSourcingTools;
use App\Services\Mcp\Sales\QuotationLifecycleTools;
use App\Services\Mcp\Sales\SalesAssistantTools;
use ReflectionMethod;
use Tests\Support\QuotationTestCase;

/** MCP-W7: suppliers, non-stock articles and quotation supplier sourcing — canonical actions, scope ∩ ERP permission ∩ store. */
class McpSupplierSourcingToolsTest extends QuotationTestCase
{
    private const SCOPES = [
        'suppliers.view', 'suppliers.create', 'suppliers.update',
        'non_stock_items.view', 'non_stock_items.create', 'non_stock_items.update',
        'quotation_sourcing.view', 'quotation_sourcing.create', 'quotation_sourcing.update',
        'quotations.view', 'quotations.create', 'quotations.update', 'quotations.issue', 'customers.view', 'products.view',
    ];

    private User $owner;

    private Organization $organization;

    private Store $storeA;

    private Store $storeB;

    private ProductVariant $variant;

    private TaxRate $tax;

    private Supplier $mifa;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-01 10:00:00'));

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->organization = $this->createOrganization($this->owner);
        $this->organization->settings = ['allow_mcp_connections' => true];
        $this->organization->save();
        $this->organization->refresh();
        $this->storeA = $this->createStore($this->organization, $this->owner, 'Store A');
        $this->storeB = $this->createStore($this->organization, $this->owner, 'Store B');
        $this->tax = $this->createTaxRate($this->organization, 'TVA 20', '20.0000');
        $this->variant = $this->createProduct($this->organization, 'Yamaha VXS8W', 'VXS8W', [
            'default_sale_price' => '1000.0000', 'tax_rate_id' => $this->tax->id,
        ])->variants->firstOrFail();
        $this->activate($this->owner, $this->organization, $this->storeA);
        $this->openStock($this->owner, $this->organization, $this->createWarehouse($this->organization), $this->variant, '2.0000');
        $this->mifa = $this->supplier($this->organization, 'MIFA', ['phone' => '0522000000', 'email' => 'contact@mifa.test']);
        $this->customer = $this->createCustomer($this->organization, 'Client X');
    }

    /* ================================================================ Suppliers */

    public function test_search_and_get_supplier(): void
    {
        $this->supplier($this->organization, 'Audio Pro', ['contact_person' => 'Samir', 'notes' => 'internal note']);
        $this->connect($this->owner);

        $found = $this->tool('searchSuppliers', ['query' => 'mif']);
        $this->assertTrue($found['ok']);
        $this->assertSame(['MIFA'], array_column($found['data']['items'], 'name'));
        $this->assertSame(['Audio Pro'], array_column($this->tool('searchSuppliers', ['query' => 'Samir'])['data']['items'], 'name'));

        $supplier = $this->tool('getSupplier', ['supplier_id' => $this->mifa->id])['data'];
        $this->assertSame('contact@mifa.test', $supplier['email']);
        $this->assertSame('active', $supplier['status']);
        $this->assertArrayNotHasKey('notes', $supplier);
    }

    public function test_preview_and_create_supplier_without_inventing_fields_and_with_duplicate_hints(): void
    {
        $this->connect($this->owner);

        $duplicate = $this->tool('previewSupplierChanges', ['supplier' => ['name' => 'mifa']])['data'];
        $this->assertTrue($duplicate['valid']);
        $this->assertSame([$this->mifa->id], array_column($duplicate['possible_duplicates'], 'id'));

        $invalid = $this->tool('previewSupplierChanges', ['supplier' => ['name' => 'ABC', 'email' => 'not-an-email']])['data'];
        $this->assertFalse($invalid['valid']);
        $this->assertNull($invalid['preview_fingerprint']);
        $this->assertSame('supplier.email', $invalid['issues'][0]['path']);

        $candidate = ['name' => 'ABC Distribution', 'phone' => '0600000000'];
        $preview = $this->tool('previewSupplierChanges', ['supplier' => $candidate])['data'];
        $this->assertTrue($preview['valid']);
        $this->assertSame([], $preview['possible_duplicates']);

        $created = $this->tool('createSupplier', ['supplier' => $candidate, 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'sup-1']);
        $this->assertTrue($created['ok'], json_encode($created));
        $supplier = Supplier::query()->findOrFail($created['data']['target']['id']);
        $this->assertSame('ABC Distribution', $supplier->name);
        $this->assertSame('0600000000', $supplier->phone);
        $this->assertNull($supplier->email);
        $this->assertNull($supplier->contact_person);
        $this->assertTrue($supplier->active);
        $log = AuditLog::query()->where('event', 'supplier.created')->where('auditable_id', $supplier->id)->sole();
        $this->assertSame('mcp', $log->actor_channel);
        $this->assertSame($this->owner->id, $log->actor_id);

        // The fingerprint is single-use.
        $this->assertSame('MCP_PREVIEW_STALE', $this->tool('createSupplier', ['supplier' => $candidate, 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'sup-other'])['error']['code']);
    }

    public function test_create_supplier_is_idempotent_and_update_requires_a_fresh_preview(): void
    {
        $this->connect($this->owner);
        $candidate = ['name' => 'Visio Import'];
        $fingerprint = $this->tool('previewSupplierChanges', ['supplier' => $candidate])['data']['preview_fingerprint'];

        $first = $this->tool('createSupplier', ['supplier' => $candidate, 'preview_fingerprint' => $fingerprint, 'idempotency_key' => 'k']);
        $replay = $this->tool('createSupplier', ['supplier' => $candidate, 'preview_fingerprint' => $fingerprint, 'idempotency_key' => 'k']);
        $this->assertTrue($replay['data']['idempotent_replay']);
        $this->assertSame($first['data']['target']['id'], $replay['data']['target']['id']);
        $this->assertSame(1, Supplier::query()->where('name', 'Visio Import')->count());
        $this->assertSame('MCP_IDEMPOTENCY_CONFLICT', $this->tool('createSupplier', ['supplier' => ['name' => 'Other'], 'preview_fingerprint' => $fingerprint, 'idempotency_key' => 'k'])['error']['code']);

        $id = $first['data']['target']['id'];
        $update = $this->tool('previewSupplierChanges', ['supplier_id' => $id, 'supplier' => ['email' => 'achat@visio.test']])['data'];
        Supplier::query()->whereKey($id)->update(['phone' => '0700', 'updated_at' => now()->addSecond()]);
        $this->assertSame('MCP_PREVIEW_STALE', $this->tool('updateSupplier', ['supplier_id' => $id, 'supplier' => ['email' => 'achat@visio.test'], 'preview_fingerprint' => $update['preview_fingerprint']])['error']['code']);

        $fresh = $this->tool('previewSupplierChanges', ['supplier_id' => $id, 'supplier' => ['email' => 'achat@visio.test']])['data'];
        $updated = $this->tool('updateSupplier', ['supplier_id' => $id, 'supplier' => ['email' => 'achat@visio.test'], 'preview_fingerprint' => $fresh['preview_fingerprint']]);
        $this->assertSame('achat@visio.test', $updated['data']['supplier']['email']);
        $this->assertSame('0700', Supplier::query()->findOrFail($id)->phone, 'Fields not supplied are kept.');
    }

    public function test_suppliers_are_organization_isolated(): void
    {
        [$otherOwner, $otherOrganization] = $this->otherOrganization();
        $foreign = $this->supplier($otherOrganization, 'MIFA Foreign');
        $this->connect($this->owner);

        $this->assertSame(['MIFA'], array_column($this->tool('searchSuppliers', ['query' => 'MIFA'])['data']['items'], 'name'));
        $this->assertSame('MCP_SUPPLIER_NOT_FOUND', $this->tool('getSupplier', ['supplier_id' => $foreign->id])['error']['code']);
        $this->assertSame('MCP_SUPPLIER_NOT_FOUND', $this->tool('previewSupplierChanges', ['supplier_id' => $foreign->id, 'supplier' => ['name' => 'X']])['error']['code']);
    }

    public function test_scope_and_current_erp_permission_are_both_required(): void
    {
        // Scope missing.
        $this->connect($this->owner, ['quotations.view']);
        $this->assertSame('MCP_FORBIDDEN', $this->tool('searchSuppliers')['error']['code']);
        $this->assertSame('MCP_FORBIDDEN', $this->tool('getQuotationSourcing', ['quotation_id' => 1])['error']['code']);

        // Scope granted, ERP permission missing (a seller without suppliers.manage / procurement.manage).
        $seller = $this->member([$this->storeA], ['quotations.view', 'quotations.update', 'suppliers.view']);
        [$quotation, $line] = $this->shortageQuotation('5');
        $this->connect($seller);
        $this->assertTrue($this->tool('searchSuppliers')['ok']);
        $this->assertSame('MCP_FORBIDDEN', $this->tool('previewSupplierChanges', ['supplier' => ['name' => 'New']])['error']['code']);
        $this->assertSame('MCP_FORBIDDEN', $this->tool('previewNonStockItemChanges', ['non_stock_item' => ['name' => 'X']])['error']['code']);
        $request = $this->tool('createSupplierAvailabilityRequest', $this->requestArgs($quotation, $line->id, 'seller-1'));
        $this->assertTrue($request['ok'], json_encode($request));
        // quotations.update grants the update scope (for cancel), but recording needs procurement.manage in the ERP.
        $this->assertSame('MCP_FORBIDDEN', $this->tool('recordSupplierAvailabilityResponse', ['quotation_id' => $quotation->id, 'supplier_request_id' => $request['data']['request']['id'], 'confirmed_quantity' => '3'])['error']['code']);
        $this->assertSame(Status::Pending, QuotationSupplierAvailabilityRequest::query()->sole()->status);
    }

    /* ================================================================ Non-stock */

    public function test_search_and_get_non_stock_items(): void
    {
        $item = $this->nonStock('Clavier XYZ Pro', ['reference' => 'XYZ-2026']);
        $this->connect($this->owner);

        $this->assertSame([$item->id], array_column($this->tool('searchNonStockItems', ['query' => 'XYZ-20'])['data']['items'], 'id'));
        $got = $this->tool('getNonStockItem', ['non_stock_item_id' => $item->id])['data'];
        $this->assertSame('Clavier XYZ Pro', $got['name']);
        $this->assertFalse($got['is_catalog_product']);
    }

    public function test_create_non_stock_item_with_exact_erp_pricing_and_idempotency(): void
    {
        $this->connect($this->owner);
        $candidate = ['name' => 'Clavier XYZ Pro', 'unit_price' => '1300', 'price_input_mode' => 'ht', 'tax_rate_id' => $this->tax->id, 'unit_label' => 'u'];

        $preview = $this->tool('previewNonStockItemChanges', ['non_stock_item' => $candidate])['data'];
        $this->assertTrue($preview['valid'], json_encode($preview));
        $this->assertSame('1300.0000', $preview['proposed']['default_price_excl_tax']);
        $this->assertSame('1560.0000', $preview['proposed']['default_price_incl_tax']);
        $this->assertSame(0, NonStockItem::query()->count(), 'Preview never persists.');

        $created = $this->tool('createNonStockItem', ['non_stock_item' => $candidate, 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'ns-1']);
        $this->assertTrue($created['ok'], json_encode($created));
        $this->assertFalse($created['data']['reused_existing']);
        $replay = $this->tool('createNonStockItem', ['non_stock_item' => $candidate, 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'ns-1']);
        $this->assertTrue($replay['data']['idempotent_replay']);
        $this->assertSame(1, NonStockItem::query()->count());
        $this->assertSame('mcp', AuditLog::query()->where('event', 'non_stock_item.created')->sole()->actor_channel);

        // Same name again: the ERP reuses the existing article (surfaced by the preview).
        $again = $this->tool('previewNonStockItemChanges', ['non_stock_item' => ['name' => 'Clavier XYZ Pro']])['data'];
        $this->assertSame($created['data']['target']['id'], $again['existing_item']['id']);

        // Invalid tax is a structured issue, not a crash.
        $badTax = $this->tool('previewNonStockItemChanges', ['non_stock_item' => ['name' => 'Autre', 'tax_rate_id' => 999999]])['data'];
        $this->assertFalse($badTax['valid']);
    }

    public function test_a_created_non_stock_item_is_quoted_without_creating_a_catalog_product(): void
    {
        $productsBefore = Product::query()->count();
        $this->connect($this->owner);
        $candidate = ['name' => 'Clavier XYZ Pro', 'unit_price' => '1300', 'tax_rate_id' => $this->tax->id];
        $preview = $this->tool('previewNonStockItemChanges', ['non_stock_item' => $candidate])['data'];
        $itemId = $this->tool('createNonStockItem', ['non_stock_item' => $candidate, 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'ns'])['data']['target']['id'];

        $quotationId = $this->createDraft([['non_stock_item_id' => $itemId, 'quantity' => '2']]);
        $quotation = Quotation::query()->with('lines')->findOrFail($quotationId);
        $this->assertSame($itemId, $quotation->lines->sole()->non_stock_item_id);
        $this->assertSame($productsBefore, Product::query()->count());
    }

    public function test_non_stock_items_are_organization_isolated(): void
    {
        [$otherOwner, $otherOrganization] = $this->otherOrganization();
        $foreign = $this->nonStock('Foreign article', [], $otherOrganization, $otherOwner);
        $this->connect($this->owner);

        $this->assertSame([], $this->tool('searchNonStockItems', ['query' => 'Foreign'])['data']['items']);
        $this->assertSame('MCP_NON_STOCK_ITEM_NOT_FOUND', $this->tool('getNonStockItem', ['non_stock_item_id' => $foreign->id])['error']['code']);
    }

    /* ================================================================ Sourcing */

    public function test_get_quotation_sourcing_for_in_stock_and_shortage_lines(): void
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->storeA);
        $inStock = $this->addCatalogQuotationLine($this->owner, $quotation, $this->variant, ['quantity' => '1']);
        $other = $this->createProduct($this->organization, 'Enceinte', 'ENC', ['default_sale_price' => '10.0000'])->variants->firstOrFail();
        $short = $this->addCatalogQuotationLine($this->owner, $quotation, $other, ['quantity' => '4']);
        $this->connect($this->owner);

        $data = $this->tool('getQuotationSourcing', ['quotation_id' => $quotation->id])['data'];
        $lines = collect($data['lines'])->keyBy('quotation_line_id');
        $this->assertFalse($lines[$inStock->id]['sourcing_required']);
        $this->assertTrue($lines[$inStock->id]['sourcing_ready']);
        $this->assertSame('in_stock', $lines[$inStock->id]['state']);
        $this->assertTrue($lines[$short->id]['sourcing_required']);
        $this->assertSame('0.0000', $lines[$short->id]['internal_available_quantity']);
        $this->assertSame('4.0000', $lines[$short->id]['shortage_quantity']);
        $this->assertSame('4.0000', $lines[$short->id]['remaining_quantity']);
        $this->assertFalse($data['sourcing_ready']);
        $this->assertSame([$short->id], $data['unresolved_lines']);
    }

    public function test_create_request_defaults_to_the_catalog_shortage_and_is_idempotent(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $this->connect($this->owner);

        $created = $this->tool('createSupplierAvailabilityRequest', $this->requestArgs($quotation, $line->id, 'req-1'));
        $this->assertTrue($created['ok'], json_encode($created));
        $this->assertSame('3.0000', $created['data']['request']['requested_quantity']);
        $this->assertSame('pending', $created['data']['request']['status']);
        $this->assertSame('MIFA', $created['data']['request']['supplier']['name']);
        $this->assertSame('pending', $created['data']['line_sourcing']['state']);
        $this->assertFalse($created['data']['quotation_sourcing']['sourcing_ready']);

        $replay = $this->tool('createSupplierAvailabilityRequest', $this->requestArgs($quotation, $line->id, 'req-1'));
        $this->assertTrue($replay['data']['idempotent_replay']);
        $this->assertSame(1, QuotationSupplierAvailabilityRequest::query()->count());
        $this->assertSame('MCP_IDEMPOTENCY_CONFLICT', $this->tool('createSupplierAvailabilityRequest', [...$this->requestArgs($quotation, $line->id, 'req-1'), 'requested_quantity' => '2'])['error']['code']);
        // A different key for the same pending supplier request is refused by the canonical duplicate guard.
        $this->assertSame('MCP_VALIDATION_FAILED', $this->tool('createSupplierAvailabilityRequest', $this->requestArgs($quotation, $line->id, 'req-2'))['error']['code']);

        $log = AuditLog::query()->where('event', 'quotation_supplier_availability.requested')->sole();
        $this->assertSame('mcp', $log->actor_channel);
    }

    public function test_a_request_cannot_target_a_line_of_another_quotation(): void
    {
        [$quotationA] = $this->shortageQuotation('5');
        [, $lineB] = $this->shortageQuotation('4');
        $this->connect($this->owner);

        $this->assertSame('MCP_QUOTATION_NOT_FOUND', $this->tool('createSupplierAvailabilityRequest', $this->requestArgs($quotationA, $lineB->id, 'x'))['error']['code']);
    }

    public function test_a_non_stock_line_requests_its_full_quantity(): void
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->storeA);
        $line = $this->addNonStockQuotationLine($this->owner, $quotation, ['name' => 'Clavier XYZ Pro', 'quantity' => '2']);
        $this->connect($this->owner);

        $created = $this->tool('createSupplierAvailabilityRequest', $this->requestArgs($quotation, $line->id, 'ns-req'));
        $this->assertSame('2.0000', $created['data']['request']['requested_quantity']);
        $this->assertNull($created['data']['line_sourcing']['internal_available_quantity']);
        $this->assertSame('non_stock', $created['data']['line_sourcing']['article_type']);
    }

    public function test_recorded_responses_derive_available_partial_and_unavailable(): void
    {
        $cases = [['3', 'available', true], ['2', 'partial', false], ['0', 'unavailable', false]];
        foreach ($cases as $index => [$confirmed, $expected, $ready]) {
            [$quotation, $line] = $this->shortageQuotation('5');
            $this->connect($this->owner);
            $requestId = $this->tool('createSupplierAvailabilityRequest', $this->requestArgs($quotation, $line->id, "case-{$index}"))['data']['request']['id'];

            $recorded = $this->tool('recordSupplierAvailabilityResponse', ['quotation_id' => $quotation->id, 'supplier_request_id' => $requestId, 'confirmed_quantity' => $confirmed, 'expected_status' => 'pending']);
            $this->assertTrue($recorded['ok'], json_encode($recorded));
            $this->assertSame($expected, $recorded['data']['request']['status']);
            $this->assertSame($ready, $recorded['data']['line_sourcing']['sourcing_ready']);
            $this->assertSame($ready, $recorded['data']['quotation_sourcing']['sourcing_ready']);
        }
        $this->assertSame('mcp', AuditLog::query()->where('event', 'quotation_supplier_availability.recorded')->latest('id')->first()->actor_channel);

        // A stale expected_status never overwrites a newer answer.
        $request = QuotationSupplierAvailabilityRequest::query()->where('status', 'partial')->sole();
        $this->assertSame('MCP_STALE_VERSION', $this->tool('recordSupplierAvailabilityResponse', ['quotation_id' => $request->quotation_id, 'supplier_request_id' => $request->id, 'confirmed_quantity' => '3', 'expected_status' => 'pending'])['error']['code']);
    }

    public function test_cancel_request_returns_the_updated_sourcing(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $this->connect($this->owner);
        $requestId = $this->tool('createSupplierAvailabilityRequest', $this->requestArgs($quotation, $line->id, 'c'))['data']['request']['id'];

        $cancelled = $this->tool('cancelSupplierAvailabilityRequest', ['quotation_id' => $quotation->id, 'supplier_request_id' => $requestId]);
        $this->assertTrue($cancelled['ok'], json_encode($cancelled));
        $this->assertSame('cancelled', $cancelled['data']['request']['status']);
        $this->assertSame('to_source', $cancelled['data']['line_sourcing']['state']);
    }

    public function test_mcp_can_never_create_or_touch_a_manual_confirmation(): void
    {
        // A manual confirmation made on the web (override at issue) on a revision-free draft path:
        [$quotation, $line] = $this->shortageQuotation('5');
        app(IssueQuotationAction::class)->execute($this->owner, $quotation->fresh(), true);
        $manual = QuotationSupplierAvailabilityRequest::query()->sole();
        $this->connect($this->owner);

        $this->assertSame('MCP_INVALID_STATE', $this->tool('recordSupplierAvailabilityResponse', ['quotation_id' => $quotation->id, 'supplier_request_id' => $manual->id, 'confirmed_quantity' => '0'])['error']['code']);
        $this->assertSame('MCP_INVALID_STATE', $this->tool('cancelSupplierAvailabilityRequest', ['quotation_id' => $quotation->id, 'supplier_request_id' => $manual->id])['error']['code']);
        $this->assertSame(Status::ConfirmedManually, $manual->fresh()->status);

        // No tool can choose a status, and issue has no override argument.
        $this->assertNotContains('status', array_map(fn ($p) => $p->getName(), (new ReflectionMethod(SupplierSourcingTools::class, 'recordSupplierAvailabilityResponse'))->getParameters()));
        $this->assertSame(['quotation_id', 'preview_fingerprint'], array_map(fn ($p) => $p->getName(), (new ReflectionMethod(QuotationLifecycleTools::class, 'issueQuotation'))->getParameters()));

        // Recording supplier answers never yields confirmed_manually.
        [$draft, $draftLine] = $this->shortageQuotation('5');
        $this->connect($this->owner);
        $requestId = $this->tool('createSupplierAvailabilityRequest', $this->requestArgs($draft, $draftLine->id, 'm'))['data']['request']['id'];
        $this->tool('recordSupplierAvailabilityResponse', ['quotation_id' => $draft->id, 'supplier_request_id' => $requestId, 'confirmed_quantity' => '9']);
        $this->assertSame(1, QuotationSupplierAvailabilityRequest::query()->where('status', Status::ConfirmedManually->value)->count());
    }

    public function test_cross_store_sourcing_access_is_rejected(): void
    {
        $this->activate($this->owner, $this->organization, $this->storeB);
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->storeB);
        $line = $this->addCatalogQuotationLine($this->owner, $quotation, $this->variant, ['quantity' => '5']);
        $request = app(\App\Actions\Quotations\CreateQuotationSupplierRequestAction::class)->execute($this->owner, $quotation, $line, ['supplier_id' => $this->mifa->id]);

        $sellerA = $this->member([$this->storeA], ['quotations.view', 'quotations.update', 'suppliers.view', 'procurement.manage']);
        $this->connect($sellerA);

        $this->assertSame('MCP_QUOTATION_NOT_FOUND', $this->tool('getQuotationSourcing', ['quotation_id' => $quotation->id])['error']['code']);
        $this->assertSame('MCP_QUOTATION_NOT_FOUND', $this->tool('createSupplierAvailabilityRequest', $this->requestArgs($quotation, $line->id, 'x'))['error']['code']);
        $this->assertSame('MCP_QUOTATION_NOT_FOUND', $this->tool('recordSupplierAvailabilityResponse', ['quotation_id' => $quotation->id, 'supplier_request_id' => $request->id, 'confirmed_quantity' => '3'])['error']['code']);
        $this->assertSame('MCP_QUOTATION_NOT_FOUND', $this->tool('cancelSupplierAvailabilityRequest', ['quotation_id' => $quotation->id, 'supplier_request_id' => $request->id])['error']['code']);
        $this->assertSame(Status::Pending, $request->fresh()->status);
    }

    public function test_cross_organization_sourcing_access_is_rejected(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        [$otherOwner, $otherOrganization] = $this->otherOrganization();
        $foreignSupplier = $this->supplier($otherOrganization, 'Foreign');

        // Another organization cannot see this quotation.
        $this->connect($otherOwner, self::SCOPES, $otherOrganization);
        $this->assertSame('MCP_QUOTATION_NOT_FOUND', $this->tool('getQuotationSourcing', ['quotation_id' => $quotation->id])['error']['code']);

        // And this organization cannot attach a foreign supplier.
        $this->connect($this->owner);
        $this->assertSame('MCP_VALIDATION_FAILED', $this->tool('createSupplierAvailabilityRequest', [...$this->requestArgs($quotation, $line->id, 'f'), 'supplier_id' => $foreignSupplier->id])['error']['code']);
    }

    public function test_get_quotation_exposes_compact_sourcing_state(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $this->connect($this->owner);

        $data = $this->tool('getQuotation', ['quotation_id' => $quotation->id], SalesAssistantTools::class)['data'];
        $this->assertSame(['evaluated' => true, 'sourcing_required' => true, 'sourcing_ready' => false, 'unresolved_sourcing_count' => 1], $data['sourcing']);
        $this->assertSame([
            'sourcing_required' => true, 'sourcing_ready' => false,
            'internal_available_quantity' => '2.0000', 'shortage_quantity' => '3.0000',
        ], $data['lines'][0]['sourcing']);
        $this->assertArrayNotHasKey('requests', $data['lines'][0]['sourcing'], 'Request history stays in get_quotation_sourcing.');
    }

    /**
     * Live regression: a draft with one non-stock line, no request yet, a supplier of the same
     * organization and no requested_quantity failed with MCP_TOOL_INTERNAL_ERROR on MySQL — the
     * idempotency target type (39 chars) overflowed mcp_idempotency_records.target_type varchar(32),
     * and the whole transaction (request included) rolled back. SQLite never enforced the length.
     */
    public function test_live_shape_non_stock_request_with_omitted_quantity_is_created_once(): void
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->storeA);
        $item = $this->nonStock('Clavier XYZ Pro', ['unit_price' => '1300', 'price_input_mode' => 'ht', 'tax_rate_id' => $this->tax->id]);
        $line = $this->addNonStockQuotationLine($this->owner, $quotation, ['non_stock_item_id' => $item->id, 'quantity' => '2']);
        $this->connect($this->owner);
        $this->assertSame([], $this->tool('getQuotationSourcing', ['quotation_id' => $quotation->id])['data']['lines'][0]['requests']);

        $created = $this->tool('createSupplierAvailabilityRequest', [
            'quotation_id' => $quotation->id, 'quotation_line_id' => $line->id, 'supplier_id' => $this->mifa->id,
            'idempotency_key' => 'live-smoke-sourcing-request-20261028-b',
        ]);

        $this->assertTrue($created['ok'], json_encode($created));
        $this->assertSame('2.0000', $created['data']['request']['requested_quantity']);
        $request = QuotationSupplierAvailabilityRequest::query()->sole();
        $this->assertSame('2.0000', $request->requested_quantity);
        $this->assertSame($item->id, $request->non_stock_item_id);

        $record = \App\Models\McpIdempotencyRecord::query()->where('tool', 'create_supplier_availability_request')->sole();
        $this->assertSame(\App\Services\Mcp\Sales\McpQuotationSourcingService::TARGET_TYPE, $record->target_type);
        $this->assertLessThanOrEqual(\App\Services\Mcp\McpIdempotencyService::TARGET_TYPE_MAX, mb_strlen($record->target_type));
        $this->assertSame((string) $request->id, $record->target_id);

        $replay = $this->tool('createSupplierAvailabilityRequest', [
            'quotation_id' => $quotation->id, 'quotation_line_id' => $line->id, 'supplier_id' => $this->mifa->id,
            'idempotency_key' => 'live-smoke-sourcing-request-20261028-b',
        ]);
        $this->assertTrue($replay['data']['idempotent_replay']);
        $this->assertSame(1, QuotationSupplierAvailabilityRequest::query()->count());
    }

    public function test_catalog_shortage_request_is_stored_with_a_fitting_idempotency_target(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $this->connect($this->owner);

        $created = $this->tool('createSupplierAvailabilityRequest', $this->requestArgs($quotation, $line->id, 'catalog-live'));
        $this->assertTrue($created['ok'], json_encode($created));
        $this->assertSame('3.0000', $created['data']['request']['requested_quantity']);
        $this->assertSame('supplier_request', \App\Models\McpIdempotencyRecord::query()->sole()->target_type);
    }

    public function test_idempotency_rejects_an_oversized_target_on_every_database_and_rolls_back(): void
    {
        $this->connect($this->owner);

        try {
            app(\App\Services\Mcp\McpIdempotencyService::class)->execute('probe', 'k', [], function () {
                $this->supplier($this->organization, 'Rolled back');

                return ['target' => ['type' => str_repeat('x', \App\Services\Mcp\McpIdempotencyService::TARGET_TYPE_MAX + 1), 'id' => 1]];
            });
            $this->fail('An oversized idempotency target must be rejected.');
        } catch (\LogicException) {
            // expected
        }

        $this->assertSame(0, Supplier::query()->where('name', 'Rolled back')->count());
        $this->assertSame(0, \App\Models\McpIdempotencyRecord::query()->count());
    }

    /* ================================================================ Issue */

    public function test_issue_is_explained_rejected_then_allowed_once_sourced(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $this->connect($this->owner);
        $requestId = $this->tool('createSupplierAvailabilityRequest', $this->requestArgs($quotation, $line->id, 'i'))['data']['request']['id'];

        $preview = $this->tool('previewQuotationIssue', ['quotation_id' => $quotation->id], QuotationLifecycleTools::class)['data'];
        $this->assertFalse($preview['valid']);
        $this->assertNull($preview['preview_fingerprint']);
        $issue = $preview['issues'][0];
        $this->assertSame('supplier_availability_unresolved', $issue['code']);
        $this->assertSame("quotation.lines.{$line->id}.sourcing", $issue['path']);
        $this->assertSame([
            'quotation_line_id' => $line->id, 'article' => 'Yamaha VXS8W', 'article_type' => 'catalog',
            'requested_quantity' => '5.0000', 'internal_available_quantity' => '2.0000', 'shortage_quantity' => '3.0000',
            'confirmed_quantity' => '0.0000', 'remaining_quantity' => '3.0000', 'state' => 'pending', 'pending_request_ids' => [$requestId],
        ], $issue['details']);

        // Without a valid preview the issue is refused, and the canonical action stays strict under MCP.
        $this->assertSame('MCP_PREVIEW_STALE', $this->tool('issueQuotation', ['quotation_id' => $quotation->id, 'preview_fingerprint' => str_repeat('a', 64)], QuotationLifecycleTools::class)['error']['code']);
        $this->assertSame(QuotationStatus::Draft, $quotation->fresh()->status);

        $this->tool('recordSupplierAvailabilityResponse', ['quotation_id' => $quotation->id, 'supplier_request_id' => $requestId, 'confirmed_quantity' => '3']);
        $ready = $this->tool('previewQuotationIssue', ['quotation_id' => $quotation->id], QuotationLifecycleTools::class)['data'];
        $this->assertTrue($ready['valid'], json_encode($ready['issues']));

        $issued = $this->tool('issueQuotation', ['quotation_id' => $quotation->id, 'preview_fingerprint' => $ready['preview_fingerprint']], QuotationLifecycleTools::class);
        $this->assertTrue($issued['ok'], json_encode($issued));
        $this->assertSame('issued', $issued['data']['quotation']['status']);
        $this->assertSame(0, QuotationSupplierAvailabilityRequest::query()->where('status', Status::ConfirmedManually->value)->count(), 'MCP issue never manually confirms.');
    }

    /* ================================================================ Full workflow */

    public function test_full_ai_workflow_supplier_quotation_request_response_issue(): void
    {
        $this->connect($this->owner);

        // 1–4. Resolve customer and supplier ("MIFA" exists; "Nouveau Fournisseur" is created from given details only).
        $this->assertSame([$this->mifa->id], array_column($this->tool('searchSuppliers', ['query' => 'MIFA'])['data']['items'], 'id'));
        $this->assertSame([], $this->tool('searchSuppliers', ['query' => 'Nouveau Fournisseur'])['data']['items']);
        $newSupplier = ['name' => 'Nouveau Fournisseur', 'phone' => '0611111111'];
        $fingerprint = $this->tool('previewSupplierChanges', ['supplier' => $newSupplier])['data']['preview_fingerprint'];
        $newSupplierId = $this->tool('createSupplier', ['supplier' => $newSupplier, 'preview_fingerprint' => $fingerprint, 'idempotency_key' => 'wf-sup'])['data']['target']['id'];

        // New article not in catalog → non-stock article from the user's data.
        $article = ['name' => 'Clavier XYZ Pro', 'unit_price' => '1300', 'tax_rate_id' => $this->tax->id];
        $articleFingerprint = $this->tool('previewNonStockItemChanges', ['non_stock_item' => $article])['data']['preview_fingerprint'];
        $articleId = $this->tool('createNonStockItem', ['non_stock_item' => $article, 'preview_fingerprint' => $articleFingerprint, 'idempotency_key' => 'wf-art'])['data']['target']['id'];

        // 6. Draft: 5 × Yamaha (2 in stock) + 2 × the new article.
        $quotationId = $this->createDraft([['variant_id' => $this->variant->id, 'quantity' => '5'], ['non_stock_item_id' => $articleId, 'quantity' => '2']]);
        $sourcing = $this->tool('getQuotationSourcing', ['quotation_id' => $quotationId])['data'];
        $lines = collect($sourcing['lines'])->keyBy('article_type');

        // 7. Requests: 3 missing Yamaha at MIFA, 2 articles at the new supplier.
        $yamaha = $this->tool('createSupplierAvailabilityRequest', ['quotation_id' => $quotationId, 'quotation_line_id' => $lines['catalog']['quotation_line_id'], 'supplier_id' => $this->mifa->id, 'idempotency_key' => 'wf-r1'])['data'];
        $keyboard = $this->tool('createSupplierAvailabilityRequest', ['quotation_id' => $quotationId, 'quotation_line_id' => $lines['non_stock']['quotation_line_id'], 'supplier_id' => $newSupplierId, 'idempotency_key' => 'wf-r2'])['data'];
        $this->assertSame('3.0000', $yamaha['request']['requested_quantity']);
        $this->assertSame('2.0000', $keyboard['request']['requested_quantity']);
        $this->assertSame(2, $keyboard['quotation_sourcing']['unresolved_count']);

        // 8. Still draft, issue preview explains both lines.
        $this->assertCount(2, $this->tool('previewQuotationIssue', ['quotation_id' => $quotationId], QuotationLifecycleTools::class)['data']['issues']);

        // 9–10. Supplier answers.
        $this->tool('recordSupplierAvailabilityResponse', ['quotation_id' => $quotationId, 'supplier_request_id' => $yamaha['request']['id'], 'confirmed_quantity' => '3']);
        $final = $this->tool('recordSupplierAvailabilityResponse', ['quotation_id' => $quotationId, 'supplier_request_id' => $keyboard['request']['id'], 'confirmed_quantity' => '2'])['data'];
        $this->assertTrue($final['quotation_sourcing']['sourcing_ready']);

        // 11. Issue.
        $preview = $this->tool('previewQuotationIssue', ['quotation_id' => $quotationId], QuotationLifecycleTools::class)['data'];
        $issued = $this->tool('issueQuotation', ['quotation_id' => $quotationId, 'preview_fingerprint' => $preview['preview_fingerprint']], QuotationLifecycleTools::class);
        $this->assertSame('issued', $issued['data']['quotation']['status']);
        $this->assertSame(1, Product::query()->count(), 'No catalog product was created for the new article.');
    }

    public function test_registry_lists_the_new_tools_by_scope_and_permission(): void
    {
        $names = $this->listTools($this->owner, ['suppliers.view', 'quotation_sourcing.view']);
        foreach (['search_suppliers', 'get_supplier', 'get_quotation_sourcing'] as $name) {
            $this->assertContains($name, $names);
        }
        foreach (['create_supplier', 'create_supplier_availability_request', 'record_supplier_availability_response', 'search_non_stock_items'] as $name) {
            $this->assertNotContains($name, $names);
        }

        // Scope granted but ERP permission missing: not listed.
        $viewer = $this->member([$this->storeA], ['quotations.view']);
        $names = $this->listTools($viewer, ['suppliers.view', 'suppliers.create', 'quotation_sourcing.view']);
        $this->assertNotContains('search_suppliers', $names);
        $this->assertNotContains('preview_supplier_changes', $names);
        $this->assertContains('get_quotation_sourcing', $names);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @param list<string> $scopes */
    private function connect(User $user, array $scopes = self::SCOPES, ?Organization $organization = null): void
    {
        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($user, $organization ?? $this->organization, 'Agent', $scopes, null);
        app(McpExecutionContext::class)->bind($issued['connection']->load(['organization', 'user', 'scopeRecords']));
    }

    /** @return array<string, mixed> */
    private function tool(string $method, array $arguments = [], string $class = SupplierSourcingTools::class): array
    {
        return app($class)->{$method}(...$arguments)->structuredContent;
    }

    /** @param list<array<string, mixed>> $items */
    private function createDraft(array $items): int
    {
        $quotation = ['store_id' => $this->storeA->id, 'customer_id' => $this->customer->id, 'items' => $items];
        $preview = $this->tool('previewQuotation', ['quotation' => $quotation], SalesAssistantTools::class)['data'];
        $this->assertTrue($preview['valid'], json_encode($preview['issues']));
        $created = $this->tool('createQuotationDraft', ['quotation' => $quotation, 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'draft-'.md5(json_encode($items))], SalesAssistantTools::class);
        $this->assertTrue($created['ok'], json_encode($created));

        return $created['data']['target']['id'];
    }

    /** @return array<string, mixed> */
    private function requestArgs(Quotation $quotation, int $lineId, string $key): array
    {
        return ['quotation_id' => $quotation->id, 'quotation_line_id' => $lineId, 'supplier_id' => $this->mifa->id, 'idempotency_key' => $key];
    }

    /** Stock 2 → a quantity above 2 creates a shortage. @return array{Quotation, \App\Models\QuotationLine} */
    private function shortageQuotation(string $quantity): array
    {
        app()->forgetScopedInstances();
        $this->activate($this->owner, $this->organization, $this->storeA);
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->storeA);
        $line = $this->addCatalogQuotationLine($this->owner, $quotation, $this->variant, ['quantity' => $quantity]);

        return [$quotation, $line];
    }

    /** @param list<Store> $stores @param list<string> $permissions */
    private function member(array $stores, array $permissions): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($this->organization, $user, $permissions);
        foreach ($stores as $store) {
            $this->addStoreMember($store, $user);
        }

        return $user;
    }

    /** @return array{User, Organization} */
    private function otherOrganization(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner, 'Other');
        $organization->settings = ['allow_mcp_connections' => true];
        $organization->save();

        return [$owner, $organization->refresh()];
    }

    /** @param array<string, mixed> $attributes */
    private function supplier(Organization $organization, string $name, array $attributes = []): Supplier
    {
        $supplier = new Supplier;
        $supplier->organization_id = $organization->id;
        $supplier->name = $name;
        $supplier->active = true;
        foreach ($attributes as $key => $value) {
            $supplier->{$key} = $value;
        }
        $supplier->save();

        return $supplier;
    }

    /** @param array<string, mixed> $attributes */
    private function nonStock(string $name, array $attributes = [], ?Organization $organization = null, ?User $actor = null): NonStockItem
    {
        return app(\App\Actions\Catalog\CreateNonStockItemAction::class)->execute($actor ?? $this->owner, $organization ?? $this->organization, ['name' => $name, ...$attributes]);
    }

    /** @param list<string> $scopes @return list<string> */
    private function listTools(User $user, array $scopes): array
    {
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
