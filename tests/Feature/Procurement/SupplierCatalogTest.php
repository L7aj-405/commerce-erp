<?php

namespace Tests\Feature\Procurement;

use App\Actions\Procurement\CreatePurchaseOrderFromProcurementRequirementsAction;
use App\Actions\Procurement\SaveProductVariantSupplierAction;
use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Actions\Quotations\CreateQuotationSupplierRequestAction;
use App\Actions\Quotations\RecordQuotationSupplierAvailabilityAction;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\ProductVariantSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrderProcurement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Procurement\SupplierCatalogQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\QuotationTestCase;

/** Phase B1: product variant ↔ supplier catalog relations (ERP/web domain). */
class SupplierCatalogTest extends QuotationTestCase
{
    private User $owner;

    private Organization $organization;

    private Store $store;

    private Warehouse $warehouse;

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
        $this->store = $this->createStore($this->organization, $this->owner);
        $this->warehouse = $this->createWarehouse($this->organization);
        $tax = $this->createTaxRate($this->organization, 'TVA 20', '20.0000');
        $product = fn (string $name, string $sku) => $this->createProduct($this->organization, $name, $sku, ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id, 'purchase_price' => '90.0000'])->variants->firstOrFail();
        $this->xlr = $product('Cordial XLR', 'COR-XLR');
        $this->shure = $product('Shure MV7X', 'SHU-MV7X');
        $this->activate($this->owner, $this->organization, $this->store);
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->shure, '5.0000');
        $this->mifa = $this->supplier($this->organization, 'MIFA');
        $this->other = $this->supplier($this->organization, 'Autre fournisseur');
    }

    public function test_a_variant_links_to_one_and_then_several_suppliers_and_a_supplier_to_several_variants(): void
    {
        $relation = $this->link($this->mifa, $this->xlr, ['purchase_price' => '120.5', 'lead_time_days' => 2, 'minimum_order_quantity' => '10', 'supplier_sku' => 'MIFA-XLR-3M']);
        $this->assertSame(['120.5000', 'MAD', '10.0000', 2, 'active', false], [$relation->purchase_price, $relation->currency_code, $relation->minimum_order_quantity, $relation->lead_time_days, $relation->status, $relation->is_preferred]);
        $this->assertSame('90.0000', $this->xlr->fresh()->purchase_price, 'The variant cost is never overwritten by a supplier price.');

        $this->link($this->other, $this->xlr, ['purchase_price' => '125.0000', 'currency_code' => 'eur']);
        $this->link($this->mifa, $this->shure);

        $byVariant = $this->catalog()->forVariants($this->organization->id, [$this->xlr->id, $this->shure->id], true);
        $this->assertSame(['Autre fournisseur', 'MIFA'], array_column(array_column($byVariant[$this->xlr->id], 'supplier'), 'name'));
        $this->assertSame('EUR', collect($byVariant[$this->xlr->id])->firstWhere('supplier.id', $this->other->id)['currency_code']);
        $this->assertCount(1, $byVariant[$this->shure->id]);

        $supplied = $this->catalog()->forSupplier($this->organization->id, $this->mifa->id, [], true);
        $this->assertSame(['COR-XLR', 'SHU-MV7X'], collect($supplied->items())->pluck('variant.sku')->all());
        $this->assertSame('Cordial XLR', $supplied->items()[0]['variant']['product']['name']);

        $created = AuditLog::query()->where('event', 'product_variant_supplier.created')->where('auditable_id', $relation->id)->sole();
        $this->assertSame([$this->owner->id, $this->mifa->id, $this->xlr->id, '120.5000'], [$created->actor_id, $created->new_values['supplier_id'], $created->new_values['product_variant_id'], $created->new_values['purchase_price']]);
    }

    public function test_a_duplicate_relation_is_refused_and_an_inactive_one_is_reactivated_instead(): void
    {
        $relation = $this->link($this->mifa, $this->xlr, ['purchase_price' => '100.0000']);
        $this->assertValidationError('supplier_id', fn () => $this->link($this->mifa, $this->xlr));

        $this->save(['status' => 'inactive'], $relation);
        $reactivated = $this->link($this->mifa, $this->xlr, ['purchase_price' => '105.0000']);

        $this->assertSame($relation->id, $reactivated->id, 'Re-linking reuses the canonical row.');
        $this->assertSame(['active', '105.0000'], [$reactivated->status, $reactivated->purchase_price]);
        $this->assertSame(1, ProductVariantSupplier::query()->count());
        $this->assertSame(['product_variant_supplier.deactivated', 'product_variant_supplier.activated'], AuditLog::query()->where('auditable_id', $relation->id)
            ->whereIn('event', ['product_variant_supplier.deactivated', 'product_variant_supplier.activated'])->orderBy('id')->pluck('event')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'product_variant_supplier.updated', 'auditable_id' => $relation->id]);
    }

    public function test_marking_a_supplier_preferred_replaces_the_previous_one_in_one_step(): void
    {
        $mifa = $this->link($this->mifa, $this->xlr, ['is_preferred' => true]);
        $other = $this->link($this->other, $this->xlr);
        $this->assertTrue($mifa->fresh()->is_preferred);

        $plan = app(SaveProductVariantSupplierAction::class)->plan($this->organization, ['is_preferred' => true], $other);
        $this->assertSame(['relation_id' => $mifa->id, 'supplier_id' => $this->mifa->id, 'supplier_name' => 'MIFA'], $plan['preferred']['unset']);

        $this->save(['is_preferred' => true], $other);
        $this->assertFalse($mifa->fresh()->is_preferred);
        $this->assertNull($mifa->fresh()->preferred_variant_id);
        $this->assertTrue($other->fresh()->is_preferred);
        $this->assertSame(1, ProductVariantSupplier::query()->where('product_variant_id', $this->xlr->id)->where('is_preferred', true)->count());

        $event = AuditLog::query()->where('event', 'product_variant_supplier.preferred_changed')->where('auditable_id', $other->id)->sole();
        $this->assertSame($mifa->id, $event->old_values['preferred']['relation_id']);
        $this->assertSame($other->id, $event->new_values['preferred']['relation_id']);

        // Deactivating the preferred relation clears the preference; an inactive relation cannot be made preferred.
        $this->save(['status' => 'inactive'], $other);
        $this->assertFalse($other->fresh()->is_preferred);
        $this->assertValidationError('is_preferred', fn () => $this->save(['status' => 'inactive', 'is_preferred' => true], $other));
    }

    public function test_the_database_allows_only_one_preferred_supplier_per_variant(): void
    {
        $this->link($this->mifa, $this->xlr, ['is_preferred' => true]);
        $other = $this->link($this->other, $this->xlr);

        // A writer bypassing the action (or a lost race) hits the unique key.
        $this->expectException(QueryException::class);
        ProductVariantSupplier::query()->whereKey($other->id)->update(['is_preferred' => true, 'preferred_variant_id' => $this->xlr->id]);
    }

    public function test_other_organizations_suppliers_and_variants_are_never_resolved(): void
    {
        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Other');
        $foreignSupplier = $this->supplier($foreign, 'Foreign');
        $foreignVariant = $this->createProduct($foreign, 'Foreign', 'FOR-1')->variants->first();

        $this->assertValidationError('supplier_id', fn () => $this->link($foreignSupplier, $this->xlr));
        $this->assertValidationError('product_variant_id', fn () => $this->link($this->mifa, $foreignVariant));
        $this->assertSame(0, ProductVariantSupplier::query()->count());

        // Another organization's relation is invisible and cannot be edited.
        $this->activate($foreignOwner, $foreign, $this->createStore($foreign, $foreignOwner));
        $foreignRelation = app(SaveProductVariantSupplierAction::class)->execute($foreignOwner, $foreign, ['supplier_id' => $foreignSupplier->id, 'product_variant_id' => $foreignVariant->id]);
        $this->activate($this->owner, $this->organization, $this->store);
        $this->assertSame([], $this->catalog()->forVariants($this->organization->id, [$foreignVariant->id], true));
        $this->actingAs($this->owner)->patch("/procurement/supplier-catalog/{$foreignRelation->id}", ['purchase_price' => '1'])->assertNotFound();
        $this->assertSame(1, ProductVariantSupplier::query()->count());
    }

    public function test_inactive_suppliers_cannot_be_linked_or_made_preferred_but_keep_their_relations(): void
    {
        $relation = $this->link($this->mifa, $this->xlr);
        $this->mifa->forceFill(['active' => false])->save();

        $this->assertValidationError('supplier_id', fn () => $this->link($this->mifa, $this->shure));
        $this->assertValidationError('supplier_id', fn () => $this->save(['is_preferred' => true], $relation));
        $this->save(['lead_time_days' => 5], $relation);
        $this->assertSame(5, $relation->fresh()->lead_time_days);
        $this->assertFalse($this->catalog()->forVariants($this->organization->id, [$this->xlr->id], true)[$this->xlr->id][0]['supplier']['active']);
    }

    public function test_commercial_fields_are_validated(): void
    {
        foreach ([
            'purchase_price' => ['-1', '1.23456', 'abc'],
            'minimum_order_quantity' => ['0', '-2'],
            'order_multiple' => ['0'],
            'lead_time_days' => [-1, 'deux'],
            'priority' => [0, 1000],
            'quality_rating' => [6, 0],
            'currency_code' => ['EURO', 'E1R'],
            'status' => ['archived'],
        ] as $field => $values) {
            foreach ($values as $value) {
                $this->assertValidationError($field, fn () => $this->link($this->mifa, $this->xlr, [$field => $value]));
            }
        }
        $this->assertValidationError('fields', fn () => $this->link($this->mifa, $this->xlr, ['organization_id' => 99]));
        $this->assertSame(0, ProductVariantSupplier::query()->count());

        $relation = $this->link($this->mifa, $this->xlr, ['purchase_price' => '0', 'minimum_order_quantity' => '0.5', 'order_multiple' => '6', 'lead_time_days' => 0, 'priority' => 1, 'quality_rating' => 5, 'reliability_rating' => 1]);
        $this->assertSame(['0.0000', '0.5000', '6.0000', 0], [$relation->purchase_price, $relation->minimum_order_quantity, $relation->order_multiple, $relation->lead_time_days]);
    }

    public function test_editing_records_only_the_changed_fields_and_an_identical_save_writes_nothing(): void
    {
        $relation = $this->link($this->mifa, $this->xlr, ['purchase_price' => '100.0000', 'lead_time_days' => 3]);
        $this->save(['purchase_price' => '110', 'notes' => 'Prix 2027'], $relation);

        $updated = AuditLog::query()->where('event', 'product_variant_supplier.updated')->where('auditable_id', $relation->id)->sole();
        $this->assertSame(['notes' => null, 'purchase_price' => '100.0000'], collect($updated->old_values)->sortKeys()->all());
        $this->assertSame('110.0000', $updated->new_values['purchase_price']);
        $this->assertSame(3, $relation->fresh()->lead_time_days, 'Omitted fields are kept.');

        $before = AuditLog::query()->count();
        $this->save(['purchase_price' => '110.0000'], $relation);
        $this->assertSame($before, AuditLog::query()->count());

        $this->assertValidationError('supplier_id', fn () => $this->save(['supplier_id' => $this->other->id], $relation));
    }

    public function test_last_purchase_signals_are_derived_from_purchase_orders(): void
    {
        $this->link($this->mifa, $this->xlr, ['purchase_price' => '120.0000']);
        $this->link($this->other, $this->xlr);
        $this->assertNull($this->catalog()->forVariants($this->organization->id, [$this->xlr->id], true)[$this->xlr->id][0]['last_purchase']);

        // The existing PO workflow needs no supplier relation and is unchanged.
        $first = $this->purchaseOrderFor('2');
        $second = $this->purchaseOrderFor('3');
        PurchaseOrderLine::query()->where('purchase_order_id', $second->id)->update(['unit_price' => '118.5000']);

        $rows = collect($this->catalog()->forVariants($this->organization->id, [$this->xlr->id], true)[$this->xlr->id])->keyBy('supplier.id');
        $last = $rows[$this->mifa->id]['last_purchase'];
        $this->assertSame([$second->id, $second->purchase_order_number, '118.5000', '3.0000', '5.0000', 2], [$last['purchase_order_id'], $last['purchase_order_number'], $last['unit_price'], $last['quantity'], $last['total_quantity'], $last['order_count']]);
        $this->assertSame('2026-10-29', $last['order_date']);
        $this->assertNull($rows[$this->other->id]['last_purchase'], 'History is per supplier.');
        $this->assertNotSame($first->purchase_order_number, $last['purchase_order_number']);

        // Without a cost/procurement view permission, prices are hidden.
        $viewer = User::factory()->create();
        $this->addOrganizationMember($this->organization, $viewer, ['products.view', 'suppliers.view']);
        $this->assertFalse(SupplierCatalogQuery::canViewPrices($viewer, $this->organization->id));
        $hidden = $this->catalog()->forSupplier($this->organization->id, $this->mifa->id, [], false)->items()[0];
        $this->assertSame([null, null], [$hidden['purchase_price'], $hidden['last_purchase']['unit_price']]);
    }

    public function test_supplier_catalog_relations_do_not_change_quotation_sourcing(): void
    {
        $this->link($this->other, $this->xlr, ['is_preferred' => true]);
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->store);
        $line = $this->addCatalogQuotationLine($this->owner, $quotation, $this->xlr, ['quantity' => '2']);
        app(CreateQuotationSupplierRequestAction::class)->execute($this->owner, $quotation->fresh(), $line, ['supplier_id' => $this->mifa->id]);

        // Quotation sourcing still remembers its product-level supplier and never writes the variant catalog.
        $this->assertTrue(ProductSupplier::query()->where('supplier_id', $this->mifa->id)->exists());
        $this->assertSame([$this->other->id], ProductVariantSupplier::query()->pluck('supplier_id')->all());
    }

    public function test_the_product_and_supplier_pages_show_the_relations_and_the_web_forms_manage_them(): void
    {
        $this->actingAs($this->owner);
        $this->post('/procurement/supplier-catalog', ['supplier_id' => $this->mifa->id, 'product_variant_id' => $this->xlr->id, 'purchase_price' => '120', 'lead_time_days' => '2', 'is_preferred' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $relation = ProductVariantSupplier::query()->sole();
        $this->assertTrue($relation->is_preferred);

        $this->get("/catalog/products/{$this->xlr->product_id}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('Catalog/Products/Show')
            ->where("supplierCatalog.{$this->xlr->id}.0.supplier.name", 'MIFA')
            ->where("supplierCatalog.{$this->xlr->id}.0.purchase_price", '120.0000')
            ->where('can.manageSuppliers', true));
        $this->get("/procurement/suppliers/{$this->mifa->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('Procurement/Suppliers/Show')
            ->where('relations.data.0.variant.sku', 'COR-XLR')
            ->where('relations.total', 1));

        $this->patch("/procurement/supplier-catalog/{$relation->id}", ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->assertSame(['inactive', false], [$relation->fresh()->status, $relation->fresh()->is_preferred]);
        $this->post('/procurement/supplier-catalog', ['supplier_id' => $this->mifa->id, 'product_variant_id' => $this->xlr->id])->assertSessionHasNoErrors();
        $this->assertSame('active', $relation->fresh()->status);

        $this->getJson('/procurement/supplier-catalog/supplier-options?search=MI')->assertOk()->assertJsonPath('data.0.name', 'MIFA');
        $this->getJson('/procurement/supplier-catalog/variant-options?search=Cordial')->assertOk()->assertJsonPath('data.0.sku', 'COR-XLR');
    }

    public function test_the_supplier_page_knows_each_variants_current_preferred_supplier_for_the_replacement_warning(): void
    {
        $this->link($this->other, $this->xlr, ['is_preferred' => true]);
        $mifaRelation = $this->link($this->mifa, $this->xlr);
        $this->actingAs($this->owner);

        $preferred = ['relation_id' => ProductVariantSupplier::query()->where('supplier_id', $this->other->id)->value('id'), 'supplier_id' => $this->other->id, 'supplier_name' => 'Autre fournisseur'];
        $this->get("/procurement/suppliers/{$this->mifa->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('relations.data.0.id', $mifaRelation->id)
            ->where('relations.data.0.variant_preferred', $preferred));
        $this->getJson('/procurement/supplier-catalog/variant-options?search=Cordial')->assertOk()
            ->assertJsonPath('data.0.preferred', $preferred);
        $this->getJson('/procurement/supplier-catalog/variant-options?search=Shure')->assertOk()
            ->assertJsonPath('data.0.preferred', null);
    }

    public function test_viewing_relations_does_not_grant_managing_them(): void
    {
        $this->link($this->mifa, $this->xlr);
        $viewer = User::factory()->create();
        $this->addOrganizationMember($this->organization, $viewer, ['products.view', 'suppliers.view', 'catalog.view']);
        $this->addStoreMember($this->store, $viewer);
        $this->activate($viewer, $this->organization, $this->store);

        $this->actingAs($viewer)->get("/catalog/products/{$this->xlr->product_id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where("supplierCatalog.{$this->xlr->id}.0.supplier.name", 'MIFA')
            ->where("supplierCatalog.{$this->xlr->id}.0.purchase_price", null)
            ->where('can.manageSuppliers', false));
        $this->actingAs($viewer)->post('/procurement/supplier-catalog', ['supplier_id' => $this->other->id, 'product_variant_id' => $this->xlr->id])->assertForbidden();

        $noSuppliers = User::factory()->create();
        $this->addOrganizationMember($this->organization, $noSuppliers, ['products.view', 'catalog.view']);
        $this->addStoreMember($this->store, $noSuppliers);
        $this->activate($noSuppliers, $this->organization, $this->store);
        $this->actingAs($noSuppliers)->get("/catalog/products/{$this->xlr->product_id}")->assertOk()->assertInertia(fn ($page) => $page->where('supplierCatalog', null));
    }

    /* ------------------------------------------------------------------ helpers */

    private function catalog(): SupplierCatalogQuery
    {
        return app(SupplierCatalogQuery::class);
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

    /** @param array<string, mixed> $data */
    private function link(Supplier $supplier, ProductVariant $variant, array $data = []): ProductVariantSupplier
    {
        return app(SaveProductVariantSupplierAction::class)->execute($this->owner, $this->organization, ['supplier_id' => $supplier->id, 'product_variant_id' => $variant->id, ...$data]);
    }

    /** @param array<string, mixed> $data */
    private function save(array $data, ProductVariantSupplier $relation): ProductVariantSupplier
    {
        return app(SaveProductVariantSupplierAction::class)->execute($this->owner, $this->organization, $data, $relation->fresh());
    }

    private function assertValidationError(string $field, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected a validation error on {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors(), json_encode($exception->errors()));
        } catch (HttpException $exception) {
            $this->fail("Unexpected HTTP {$exception->getStatusCode()} for {$field}.");
        }
    }

    /** A sales order short of XLR cables, confirmed by MIFA, ordered on a purchase order. */
    private function purchaseOrderFor(string $quantity): PurchaseOrder
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->store);
        $line = $this->addCatalogQuotationLine($this->owner, $quotation, $this->xlr, ['quantity' => $quantity]);
        $request = app(CreateQuotationSupplierRequestAction::class)->execute($this->owner, $quotation->fresh(), $line, ['supplier_id' => $this->mifa->id]);
        app(RecordQuotationSupplierAvailabilityAction::class)->execute($this->owner, $quotation->fresh(), $request, ['outcome' => 'available', 'confirmed_quantity' => $quantity]);
        $issued = $this->issueQuotation($this->owner, $quotation->fresh(), false);
        $order = app(ConvertQuotationToSalesOrderAction::class)->execute($this->owner, $this->acceptQuotation($this->owner, $issued), ['warehouse_id' => $this->warehouse->id])['order'];
        $requirement = SalesOrderProcurement::query()->where('sales_order_id', $order->id)->sole();

        return app(CreatePurchaseOrderFromProcurementRequirementsAction::class)->execute($this->owner, $this->organization, $this->mifa, [$requirement->id]);
    }
}
