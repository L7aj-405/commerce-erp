<?php

namespace Tests\Feature\Procurement;

use App\Actions\Procurement\CreatePurchaseOrderFromProcurementRequirementsAction;
use App\Actions\Procurement\SaveProductVariantSupplierAction;
use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Actions\Quotations\CreateQuotationSupplierRequestAction;
use App\Actions\Quotations\RecordQuotationSupplierAvailabilityAction;
use App\Enums\QuotationSupplierAvailabilityStatus;
use App\Models\Organization;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\ProductVariantSupplier;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\SalesOrderProcurement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Procurement\SupplierRecommendationService;
use Carbon\CarbonImmutable;
use Tests\Support\QuotationTestCase;

/** Phase B2: explainable supplier recommendations. */
class SupplierRecommendationTest extends QuotationTestCase
{
    private User $owner;

    private Organization $organization;

    private Store $store;

    private Warehouse $warehouse;

    private ProductVariant $xlr;

    private ProductVariant $shure;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->organization, $this->owner);
        $this->warehouse = $this->createWarehouse($this->organization);
        $tax = $this->createTaxRate($this->organization, 'TVA 20', '20.0000');
        $this->xlr = $this->createProduct($this->organization, 'Cordial XLR', 'COR-XLR', ['default_sale_price' => '200.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->shure = $this->createProduct($this->organization, 'Shure MV7X', 'SHU-MV7X', ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($this->owner, $this->organization, $this->store);
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->shure, '5.0000');
    }

    /* ------------------------------------------------------------ ranking */

    public function test_the_preferred_supplier_ranks_first_and_is_explained(): void
    {
        $this->link('Zeta', ['lead_time_days' => 1, 'purchase_price' => '50.0000', 'reliability_rating' => 5]);
        $this->link('MIFA', ['is_preferred' => true, 'lead_time_days' => 2]);

        $top = $this->recommend()[0];
        $this->assertSame(['MIFA', 'A', 1], [$top['supplier']['name'], $top['tier'], $top['rank']]);
        $this->assertContains('preferred_supplier', array_column($top['reasons'], 'code'));
        $this->assertStringStartsWith('MIFA (tier A) is recommended because it is the preferred supplier', $top['explanation']);
    }

    public function test_explicit_priority_then_moq_compatibility_order_equivalent_suppliers(): void
    {
        $this->link('Alpha', ['priority' => 2, 'lead_time_days' => 1]);
        $this->link('Beta', ['priority' => 1, 'lead_time_days' => 9]);
        $this->assertSame(['Beta', 'Alpha'], $this->names($this->recommend()));

        $this->link('Big MOQ', ['minimum_order_quantity' => '10', 'lead_time_days' => 1]);
        $this->link('Small MOQ', ['minimum_order_quantity' => '1', 'lead_time_days' => 5]);
        $ranked = $this->recommend('2');
        $this->assertSame(['Beta', 'Alpha', 'Small MOQ', 'Big MOQ'], $this->names($ranked));
        $big = $this->find($ranked, 'Big MOQ');
        $this->assertSame([false, '10.0000'], [$big['quantity']['moq_compatible'], $big['quantity']['suggested_order_quantity']]);
        $this->assertSame(['minimum' => '10.0000', 'requested' => '2.0000'], $this->warning($big, 'moq_not_met')['params']);
    }

    public function test_lead_time_then_reliability_then_quality_break_ties(): void
    {
        $this->link('Slow', ['lead_time_days' => 7, 'purchase_price' => '1', 'reliability_rating' => 5]);
        $this->link('Fast', ['lead_time_days' => 2, 'purchase_price' => '1']);
        $this->link('Fast reliable', ['lead_time_days' => 2, 'purchase_price' => '1', 'reliability_rating' => 4]);
        $this->link('Fast reliable quality', ['lead_time_days' => 2, 'purchase_price' => '1', 'reliability_rating' => 4, 'quality_rating' => 5]);

        $ranked = $this->recommend();
        $this->assertSame(['Fast reliable quality', 'Fast reliable', 'Fast', 'Slow'], $this->names($ranked));
        $this->assertSame(['days' => 2], $this->reason($ranked[0], 'fastest_lead_time')['params']);
        $this->assertSame(['days' => 7], $this->reason($ranked[3], 'known_lead_time')['params']);
        $this->assertNotNull($this->reason($ranked[0], 'high_quality'));
    }

    public function test_an_authorized_viewer_ranks_by_same_currency_price_and_other_currencies_are_never_compared(): void
    {
        // Names deliberately disagree with prices: Alpha is the dearest MAD price.
        $this->link('Alpha', ['purchase_price' => '130.0000', 'lead_time_days' => 3]);
        $this->link('Bravo', ['purchase_price' => '120.0000', 'lead_time_days' => 3]);
        $this->link('Euro', ['purchase_price' => '10.0000', 'currency_code' => 'EUR', 'lead_time_days' => 3]);

        $ranked = $this->recommend();
        $this->assertSame(['Bravo', 'Alpha', 'Euro'], $this->names($ranked), 'A 10 EUR price is not "cheaper" than 120 MAD.');
        $this->assertSame(['B', 'B', 'B'], array_column($ranked, 'tier'), 'Price + lead time are complete terms for this viewer.');
        $this->assertSame(['price' => '120.0000', 'currency' => 'MAD'], $this->reason($ranked[0], 'best_known_price')['params']);
        $this->assertSame(['currency' => 'EUR', 'reference_currency' => 'MAD'], $this->warning($ranked[2], 'price_currency_not_comparable')['params']);
        $this->assertNull($this->reason($ranked[2], 'best_known_price'));
    }

    public function test_without_price_visibility_prices_never_influence_or_leak_through_the_ranking(): void
    {
        $this->link('Alpha', ['purchase_price' => '130.0000', 'lead_time_days' => 3]);
        $this->link('Bravo', ['purchase_price' => '120.0000', 'lead_time_days' => 3]);
        $this->link('Euro', ['purchase_price' => '10.0000', 'currency_code' => 'EUR', 'lead_time_days' => 3]);
        $this->link('Zulu', ['lead_time_days' => 3]);

        $hidden = $this->recommend(withPrices: false);
        // Only non-price signals remain: equal lead times, then the supplier name.
        $this->assertSame(['Alpha', 'Bravo', 'Euro', 'Zulu'], $this->names($hidden));
        $this->assertSame(['C', 'C', 'C', 'C'], array_column($hidden, 'tier'), 'A known price is not "evidence" for a viewer who cannot see it.');
        foreach ($hidden as $recommendation) {
            $this->assertNull($recommendation['purchase_price']);
            $this->assertFalse($recommendation['prices_visible']);
            $codes = [...array_column($recommendation['reasons'], 'code'), ...array_column($recommendation['warnings'], 'code')];
            $this->assertSame([], array_values(array_intersect($codes, ['best_known_price', 'missing_price', 'price_currency_not_comparable'])),
                "{$recommendation['supplier']['name']} must not reveal whether or how a price is known.");
            $this->assertStringNotContainsString('price', $recommendation['explanation']);
        }

        // Changing a price does not move anything for this viewer.
        app(SaveProductVariantSupplierAction::class)->execute($this->owner, $this->organization, ['purchase_price' => '1.0000'],
            ProductVariantSupplier::query()->where('supplier_id', $this->supplier('Euro')->id)->sole());
        $this->assertSame($hidden, $this->recommend(withPrices: false));
        $this->assertSame('Euro', $this->recommend()[2]['supplier']['name'], 'The EUR price stays uncompared for an authorized viewer.');
    }

    public function test_recent_purchases_promote_to_the_evidence_tier_and_are_explained(): void
    {
        $this->link('MIFA');
        $this->link('Autre');
        $this->assertSame(['C', 'C'], array_column($this->recommend(), 'tier'));

        $po = $this->purchaseOrder($this->supplier('MIFA'));
        $ranked = $this->recommend();
        $this->assertSame(['MIFA', 'B'], [$ranked[0]['supplier']['name'], $ranked[0]['tier']]);
        $this->assertSame(['date' => '2026-10-29', 'purchase_order_number' => $po->purchase_order_number], $this->reason($ranked[0], 'recently_purchased')['params']);
        $this->assertSame($po->purchase_order_number, $ranked[0]['last_purchase']['purchase_order_number']);

        // An old purchase no longer counts as recent (it is still shown).
        $this->travel(400)->days();
        $old = $this->find($this->recommend(), 'MIFA');
        $this->assertSame('C', $old['tier']);
        $this->assertNull($this->reason($old, 'recently_purchased'));
        $this->assertNotNull($old['last_purchase']);
    }

    public function test_recent_availability_answers_count_and_old_ones_are_ignored(): void
    {
        $this->link('Confirmed');
        $this->link('Refused', ['priority' => 1]);
        $this->link('Silent');
        $this->answer('Confirmed', QuotationSupplierAvailabilityStatus::Available, daysAgo: 10);
        $this->answer('Refused', QuotationSupplierAvailabilityStatus::Unavailable, daysAgo: 5);

        $ranked = $this->recommend();
        // A recent refusal costs the evidence tier even with a priority; inside tier C the explicit priority still applies.
        $this->assertSame(['Confirmed', 'Refused', 'Silent'], $this->names($ranked));
        $this->assertSame(['B', 'C', 'C'], array_column($ranked, 'tier'));
        $this->assertNotNull($this->reason($ranked[0], 'recently_confirmed_available'));
        $this->assertNotNull($this->warning($ranked[1], 'recently_unavailable'));
        $this->assertSame(1, $ranked[0]['availability']['confirmed_count']);

        // Outside the lookback window the answers are ignored.
        $this->travel(SupplierRecommendationService::AVAILABILITY_LOOKBACK_DAYS + 1)->days();
        $later = $this->recommend();
        $this->assertSame(['Refused', 'Confirmed', 'Silent'], $this->names($later), 'Only the explicit priority remains.');
        $this->assertNull($this->find($later, 'Refused')['availability']['latest_status']);
    }

    public function test_inactive_relations_and_suppliers_are_excluded_and_other_organizations_never_appear(): void
    {
        $this->link('Active');
        $this->save('Inactive relation', [], fn ($relation) => ['status' => 'inactive']);
        $this->link('Inactive supplier');
        $this->supplier('Inactive supplier')->forceFill(['active' => false])->save();

        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Other');
        $foreignSupplier = new Supplier;
        $foreignSupplier->forceFill(['organization_id' => $foreign->id, 'name' => 'Foreign', 'active' => true])->save();
        $foreignVariant = $this->createProduct($foreign, 'Foreign', 'FOR-1')->variants->first();
        ProductVariantSupplier::query()->insert(['organization_id' => $foreign->id, 'supplier_id' => $foreignSupplier->id, 'product_variant_id' => $foreignVariant->id,
            'currency_code' => 'MAD', 'status' => 'active', 'is_preferred' => false, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['Active'], $this->names($this->recommend()));
        $this->assertSame([], app(SupplierRecommendationService::class)->recommendForVariants($this->organization->id, ['x' => ['product_variant_id' => $foreignVariant->id, 'requested_quantity' => '1']], true)['x']['recommendations']);
    }

    public function test_no_linked_supplier_and_missing_data_do_not_break_ranking(): void
    {
        $this->assertSame([], $this->recommend());

        $this->link('Bare');
        $bare = $this->recommend()[0];
        $this->assertSame(['C', null, null], [$bare['tier'], $bare['purchase_price'], $bare['lead_time_days']]);
        $this->assertSame(['missing_lead_time', 'missing_price'], array_column($bare['warnings'], 'code'));
        $this->assertSame('Bare (tier C) is a linked supplier; note: no lead time is known, no price is known.', $bare['explanation']);
    }

    public function test_ranking_is_deterministic_and_order_multiple_suggests_a_quantity(): void
    {
        foreach (['Delta', 'alpha', 'Charlie', 'bravo'] as $name) $this->link($name, ['order_multiple' => '5']);
        $first = $this->recommend('7');
        $this->assertSame(['alpha', 'bravo', 'Charlie', 'Delta'], $this->names($first), 'Equal suppliers fall back to the name.');
        $this->assertSame($first, $this->recommend('7'));

        $quantity = $first[0]['quantity'];
        $this->assertSame(['7.0000', '5.0000', false, '10.0000'], [$quantity['requested_quantity'], $quantity['order_multiple'], $quantity['multiple_compatible'], $quantity['suggested_order_quantity']]);
        $this->assertSame(['suggested' => '10.0000', 'multiple' => '5.0000'], $this->warning($first[0], 'order_multiple_adjustment')['params']);
        $this->assertNull($this->recommend('10')[0]['quantity']['suggested_order_quantity']);

        // MOQ then multiple: 2 requested, MOQ 6, multiple 4 → 8.
        $this->link('Echo', ['minimum_order_quantity' => '6', 'order_multiple' => '4']);
        $this->assertSame('8.0000', $this->find($this->recommend('2'), 'Echo')['quantity']['suggested_order_quantity']);
    }

    /* ------------------------------------------------------------ quotation sourcing */

    public function test_a_shortage_line_gets_recommendations_without_creating_anything_and_a_covered_line_does_not(): void
    {
        $this->link('MIFA', ['is_preferred' => true, 'lead_time_days' => 2]);
        [$quotation, $xlrLine] = $this->quotationWith($this->xlr, '2');
        $shureLine = $this->addCatalogQuotationLine($this->owner, $quotation, $this->shure, ['quantity' => '1']);

        $this->actingAs($this->owner);
        $response = $this->getJson("/quotations/{$quotation->id}/lines/{$xlrLine->id}/supplier-recommendations")->assertOk();
        $response->assertJsonPath('data.recommendation_needed', true)
            ->assertJsonPath('data.requested_quantity', '2.0000')
            ->assertJsonPath('data.recommendations.0.supplier.name', 'MIFA')
            ->assertJsonPath('data.recommendations.0.eligible_for_request', true);
        $this->getJson("/quotations/{$quotation->id}/lines/{$shureLine->id}/supplier-recommendations")->assertOk()
            ->assertJsonPath('data.recommendation_needed', false)->assertJsonPath('data.recommendations', []);

        $this->assertSame(0, QuotationSupplierAvailabilityRequest::query()->count(), 'Viewing recommendations never creates a request.');
    }

    public function test_choosing_a_recommendation_or_an_unlinked_supplier_uses_the_canonical_request(): void
    {
        $this->link('MIFA', ['is_preferred' => true]);
        $unlinked = $this->supplier('Unlinked');
        [$quotation, $line] = $this->quotationWith($this->xlr, '2');
        $this->actingAs($this->owner);

        $this->post("/quotations/{$quotation->id}/lines/{$line->id}/supplier-requests", ['supplier_id' => $this->supplier('MIFA')->id])->assertSessionHasNoErrors();
        $this->getJson("/quotations/{$quotation->id}/lines/{$line->id}/supplier-recommendations")
            ->assertJsonPath('data.recommendations.0.eligible_for_request', false)
            ->assertJsonPath('data.recommendations.0.ineligible_reason', 'request_pending_for_line');

        $this->post("/quotations/{$quotation->id}/lines/{$line->id}/supplier-requests", ['supplier_id' => $unlinked->id])->assertSessionHasNoErrors();
        $this->assertSame(2, QuotationSupplierAvailabilityRequest::query()->where('quotation_line_id', $line->id)->count());
        $this->assertFalse(ProductVariantSupplier::query()->where('supplier_id', $unlinked->id)->exists(), 'No hidden relation creation.');
        $this->assertTrue(ProductSupplier::query()->where('supplier_id', $unlinked->id)->exists(), 'Legacy sourcing memory is preserved.');

        // The unlinked supplier is now a legacy (tier D) suggestion, after linked suppliers.
        $ranked = $this->getJson("/quotations/{$quotation->id}/lines/{$line->id}/supplier-recommendations")->json('data.recommendations');
        $this->assertSame([['MIFA', 'A', 'catalog'], ['Unlinked', 'D', 'legacy']], array_map(fn ($r) => [$r['supplier']['name'], $r['tier'], $r['source']], $ranked));
        $this->assertSame('legacy_suggestion', $ranked[1]['warnings'][0]['code']);

        // Recording the answer and the issue gate are unchanged; the answer never mutates the catalog.
        $request = QuotationSupplierAvailabilityRequest::query()->where('supplier_id', $this->supplier('MIFA')->id)->sole();
        app(RecordQuotationSupplierAvailabilityAction::class)->execute($this->owner, $quotation->fresh(), $request, ['outcome' => 'available', 'confirmed_quantity' => '2']);
        $this->assertSame(QuotationSupplierAvailabilityStatus::Available, $request->fresh()->status);
        $relation = ProductVariantSupplier::query()->where('supplier_id', $this->supplier('MIFA')->id)->sole();
        $this->assertSame([true, null], [$relation->is_preferred, $relation->lead_time_days]);
        $this->assertSame('issued', $this->issueQuotation($this->owner, $quotation->fresh(), false)->status->value);
    }

    public function test_the_sales_order_shows_read_only_supplier_price_hints_for_lines_to_order(): void
    {
        $this->link('MIFA', ['purchase_price' => '120.0000']);
        [$quotation, $line] = $this->quotationWith($this->xlr, '2');
        $request = app(CreateQuotationSupplierRequestAction::class)->execute($this->owner, $quotation->fresh(), $line, ['supplier_id' => $this->supplier('MIFA')->id]);
        app(RecordQuotationSupplierAvailabilityAction::class)->execute($this->owner, $quotation->fresh(), $request, ['outcome' => 'available', 'confirmed_quantity' => '2']);
        $order = app(ConvertQuotationToSalesOrderAction::class)->execute($this->owner, $this->acceptQuotation($this->owner, $this->issueQuotation($this->owner, $quotation->fresh(), false)), ['warehouse_id' => $this->warehouse->id])['order'];
        $orderLine = $order->lines()->where('product_variant_id', $this->xlr->id)->sole();

        $this->actingAs($this->owner)->get("/sales/orders/{$order->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where("procurementPlan.price_hints.{$orderLine->id}.catalog_price", '120.0000')
            ->where("procurementPlan.price_hints.{$orderLine->id}.last_purchase_price", null)
            ->where("procurementPlan.price_hints.{$orderLine->id}.variance", null));
    }

    /* ------------------------------------------------------------ helpers */

    /** @return list<array<string, mixed>> */
    private function recommend(string $quantity = '2', bool $withPrices = true): array
    {
        return app(SupplierRecommendationService::class)->recommendForVariants($this->organization->id, ['x' => ['product_variant_id' => $this->xlr->id, 'requested_quantity' => $quantity]], $withPrices)['x']['recommendations'];
    }

    /** @param list<array<string, mixed>> $ranked @return list<string> */
    private function names(array $ranked): array
    {
        return array_map(fn ($recommendation) => $recommendation['supplier']['name'], $ranked);
    }

    /** @param list<array<string, mixed>> $ranked @return array<string, mixed> */
    private function find(array $ranked, string $name): array
    {
        return collect($ranked)->firstWhere('supplier.name', $name);
    }

    /** @param array<string, mixed> $recommendation @return array<string, mixed>|null */
    private function reason(array $recommendation, string $code): ?array
    {
        return collect($recommendation['reasons'])->firstWhere('code', $code);
    }

    /** @param array<string, mixed> $recommendation @return array<string, mixed>|null */
    private function warning(array $recommendation, string $code): ?array
    {
        return collect($recommendation['warnings'])->firstWhere('code', $code);
    }

    private function supplier(string $name): Supplier
    {
        return Supplier::query()->where('organization_id', $this->organization->id)->where('name', $name)->first()
            ?? tap(new Supplier, fn (Supplier $supplier) => $supplier->forceFill(['organization_id' => $this->organization->id, 'name' => $name, 'active' => true])->save());
    }

    /** @param array<string, mixed> $data */
    private function link(string $supplier, array $data = []): ProductVariantSupplier
    {
        return app(SaveProductVariantSupplierAction::class)->execute($this->owner, $this->organization, ['supplier_id' => $this->supplier($supplier)->id, 'product_variant_id' => $this->xlr->id, ...$data]);
    }

    /** Link, then apply an update computed from the relation. */
    private function save(string $supplier, array $data, callable $then): void
    {
        $relation = $this->link($supplier, $data);
        app(SaveProductVariantSupplierAction::class)->execute($this->owner, $this->organization, $then($relation), $relation);
    }

    /** @return array{Quotation, QuotationLine} */
    private function quotationWith(ProductVariant $variant, string $quantity): array
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->store);
        $line = $this->addCatalogQuotationLine($this->owner, $quotation, $variant, ['quantity' => $quantity]);

        return [$quotation->fresh(), $line];
    }

    /** A recorded supplier answer for the XLR, `daysAgo` days ago. */
    private function answer(string $supplier, QuotationSupplierAvailabilityStatus $status, int $daysAgo): void
    {
        [$quotation, $line] = $this->quotationWith($this->xlr, '2');
        $at = now()->subDays($daysAgo);
        $request = new QuotationSupplierAvailabilityRequest;
        $request->forceFill([
            'organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'quotation_id' => $quotation->id, 'quotation_line_id' => $line->id,
            'supplier_id' => $this->supplier($supplier)->id, 'product_variant_id' => $this->xlr->id, 'requested_quantity' => '2.0000',
            'confirmed_quantity' => $status === QuotationSupplierAvailabilityStatus::Available ? '2.0000' : '0.0000',
            'status' => $status, 'requested_by_user_id' => $this->owner->id, 'requested_at' => $at, 'resolved_by_user_id' => $this->owner->id, 'resolved_at' => $at,
        ])->save();
    }

    /** An XLR shortage ordered from the supplier on a purchase order (the canonical procurement path). */
    private function purchaseOrder(Supplier $supplier): PurchaseOrder
    {
        [$quotation, $line] = $this->quotationWith($this->xlr, '2');
        $request = app(CreateQuotationSupplierRequestAction::class)->execute($this->owner, $quotation, $line, ['supplier_id' => $supplier->id]);
        app(RecordQuotationSupplierAvailabilityAction::class)->execute($this->owner, $quotation->fresh(), $request, ['outcome' => 'available', 'confirmed_quantity' => '2']);
        $order = app(ConvertQuotationToSalesOrderAction::class)->execute($this->owner, $this->acceptQuotation($this->owner, $this->issueQuotation($this->owner, $quotation->fresh(), false)), ['warehouse_id' => $this->warehouse->id])['order'];
        $requirement = SalesOrderProcurement::query()->where('sales_order_id', $order->id)->sole();
        // The confirmed answer above is not the subject of this test.
        QuotationSupplierAvailabilityRequest::query()->whereKey($request->id)->update(['requested_at' => now()->subYears(2), 'resolved_at' => now()->subYears(2)]);

        return app(CreatePurchaseOrderFromProcurementRequirementsAction::class)->execute($this->owner, $this->organization, $supplier, [$requirement->id]);
    }
}
