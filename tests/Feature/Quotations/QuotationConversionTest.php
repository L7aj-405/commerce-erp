<?php

namespace Tests\Feature\Quotations;

use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Actions\Quotations\DecideQuotationAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderLineType;
use App\Enums\SalesOrderStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

class QuotationConversionTest extends QuotationTestCase
{
    /** @return array{User, Organization, Store, ProductVariant, Warehouse} */
    private function fixture(string $stock = '50.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Ampli', 'AMP-1', ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, $stock);

        return [$owner, $organization, $store, $variant, $warehouse];
    }

    public function test_an_accepted_devis_converts_into_a_draft_sales_order_without_retyping_lines(): void
    {
        [$owner, $organization, $store, $variant, $warehouse] = $this->fixture();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant, ['quantity' => '3']);
        $this->addNonStockQuotationLine($owner, $quotation, ['name' => 'Installation', 'unit_price' => '800', 'quantity' => '1']);
        $issued = $this->issueQuotation($owner, $quotation);

        $result = app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $this->acceptQuotation($owner, $issued), ['warehouse_id' => $warehouse->id]);
        $order = $result['order'];

        $this->assertSame(SalesOrderStatus::Draft, $order->status);
        $this->assertSame($owner->id, $order->salesperson_id);
        $this->assertSame($owner->name, $order->salesperson_name_snapshot);
        $this->assertSame(2, $order->lines()->count());
        $catalogLine = $order->lines()->where('line_type', SalesOrderLineType::Catalog->value)->firstOrFail();
        $customLine = $order->lines()->where('line_type', SalesOrderLineType::Custom->value)->firstOrFail();
        $this->assertSame('3.0000', $catalogLine->quantity);
        $this->assertSame('1000.0000', $catalogLine->unit_price_excl_tax);
        $this->assertSame('Installation', $customLine->product_name);
        $this->assertNull($customLine->product_variant_id);

        $issued = $issued->fresh();
        $this->assertSame(QuotationStatus::Converted, $issued->status);
        $this->assertSame($order->getKey(), $issued->converted_sales_order_id);
        $this->assertNotNull($issued->converted_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'quotation.converted', 'auditable_id' => $issued->id]);
    }

    public function test_conversion_is_idempotent_under_double_submit(): void
    {
        [$owner, $organization, $store, $variant, $warehouse] = $this->fixture();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant, ['quantity' => '1']);
        $issued = $this->issueQuotation($owner, $quotation);

        $first = app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $this->acceptQuotation($owner, $issued), ['warehouse_id' => $warehouse->id]);
        $second = app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $issued->fresh(), ['warehouse_id' => $warehouse->id]);

        $this->assertFalse($first['reused']);
        $this->assertTrue($second['reused']);
        $this->assertSame($first['order']->getKey(), $second['order']->getKey());
        $this->assertSame(1, SalesOrder::query()->where('organization_id', $organization->id)->count());
    }

    public function test_no_inventory_is_touched_before_conversion_but_confirmation_reserves_stock(): void
    {
        [$owner, $organization, $store, $variant, $warehouse] = $this->fixture('10.0000');
        $reservationsBefore = InventoryReservation::query()->count();
        $movementsBefore = InventoryMovement::query()->count();

        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant, ['quantity' => '4']);
        $issued = $this->issueQuotation($owner, $quotation);

        // Still nothing reserved by the Devis itself.
        $this->assertSame($reservationsBefore, InventoryReservation::query()->count());

        $order = app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $this->acceptQuotation($owner, $issued), ['warehouse_id' => $warehouse->id])['order'];
        // Draft order: allocations exist, but still no reservation.
        $this->assertSame($reservationsBefore, InventoryReservation::query()->count());

        // Confirmation uses the EXISTING company-wide reservation architecture.
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertSame($reservationsBefore + 1, InventoryReservation::query()->count());
        $this->assertSame($movementsBefore, InventoryMovement::query()->count());
    }

    public function test_conversion_reports_a_stock_warning_when_quoted_quantity_exceeds_availability(): void
    {
        [$owner, $organization, $store, $variant, $warehouse] = $this->fixture('2.0000');
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant, ['quantity' => '9']);
        $issued = $this->issueQuotation($owner, $quotation);

        $result = app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $this->acceptQuotation($owner, $issued), ['warehouse_id' => $warehouse->id]);

        $this->assertNotEmpty($result['stock_warnings']);
        $this->assertSame('Ampli', $result['stock_warnings'][0]['product_name']);
        // Conversion still succeeded — a Devis is never rejected on stock.
        $this->assertSame(QuotationStatus::Converted, $issued->fresh()->status);
    }

    public function test_a_draft_devis_cannot_be_converted(): void
    {
        [$owner, $organization, $store, $variant, $warehouse] = $this->fixture();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);

        $this->expectException(ValidationException::class);
        app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $quotation, ['warehouse_id' => $warehouse->id]);
    }

    // ---- Accepted-only conversion guard -----------------------------------

    public function test_an_issued_devis_cannot_be_converted_before_acceptance_and_nothing_is_written(): void
    {
        [$owner, $organization, $store, $variant, $warehouse] = $this->fixture();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant, ['quantity' => '2']);
        $issued = $this->issueQuotation($owner, $quotation);
        $before = $this->sideEffectCounts();

        try {
            app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $issued, ['warehouse_id' => $warehouse->id]);
            $this->fail('An issued (not accepted) Devis must not convert.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quotation', $exception->errors());
        }

        $this->assertSame($before, $this->sideEffectCounts());
        $issued = $issued->fresh();
        $this->assertSame(QuotationStatus::Issued, $issued->status, 'Conversion never implies acceptance.');
        $this->assertNull($issued->accepted_at);
        $this->assertNull($issued->converted_sales_order_id);
        $this->assertNull($issued->converted_at);
    }

    public function test_a_refused_devis_cannot_be_converted_and_stays_refused(): void
    {
        [$owner, $organization, $store, $variant, $warehouse] = $this->fixture();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $rejected = app(DecideQuotationAction::class)->reject($owner, $this->issueQuotation($owner, $quotation), 'Trop cher');
        $before = $this->sideEffectCounts();

        try {
            app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $rejected, ['warehouse_id' => $warehouse->id]);
            $this->fail('A refused Devis must not convert.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($before, $this->sideEffectCounts());
        $rejected = $rejected->fresh();
        $this->assertSame(QuotationStatus::Rejected, $rejected->status);
        $this->assertNotNull($rejected->rejected_at);
        $this->assertSame('Trop cher', $rejected->rejection_reason);
        $this->assertNull($rejected->converted_sales_order_id);
    }

    public function test_a_crafted_web_request_cannot_convert_an_issued_devis(): void
    {
        [$owner, $organization, $store, $variant, $warehouse] = $this->fixture();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);
        $before = $this->sideEffectCounts();

        $this->actingAs($owner)->post(route('quotations.conversion.store', $issued), ['warehouse_id' => $warehouse->id])->assertForbidden();

        $this->assertSame($before, $this->sideEffectCounts());
        $this->assertSame(QuotationStatus::Issued, $issued->fresh()->status);
    }

    public function test_issue_accept_then_convert_over_http_creates_one_order_and_keeps_acceptance_trail(): void
    {
        [$owner, $organization, $store, $variant, $warehouse] = $this->fixture();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $accepted = $this->acceptQuotation($owner, $this->issueQuotation($owner, $quotation));
        $this->assertSame(QuotationStatus::Accepted, $accepted->status);

        $response = $this->actingAs($owner)->post(route('quotations.conversion.store', $accepted), ['warehouse_id' => $warehouse->id]);
        $order = SalesOrder::query()->where('organization_id', $organization->id)->sole();
        $response->assertRedirect(route('sales.orders.show', $order));

        // A repeated submit is idempotent (Converted stays reachable for that).
        $this->actingAs($owner)->post(route('quotations.conversion.store', $accepted), ['warehouse_id' => $warehouse->id])->assertRedirect(route('sales.orders.show', $order));
        $this->assertSame(1, SalesOrder::query()->where('organization_id', $organization->id)->count());

        $accepted = $accepted->fresh();
        $this->assertSame(QuotationStatus::Converted, $accepted->status);
        $this->assertSame($order->id, $accepted->converted_sales_order_id);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertSame($owner->id, $accepted->accepted_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'quotation.accepted', 'auditable_id' => $accepted->id]);
    }

    public function test_detail_page_exposes_conversion_only_for_an_accepted_devis(): void
    {
        [$owner, $organization, $store, $variant] = $this->fixture();
        $devis = function () use ($owner, $organization, $store, $variant): Quotation {
            $quotation = $this->createQuotation($owner, $organization, $store);
            $this->addCatalogQuotationLine($owner, $quotation, $variant);

            return $this->issueQuotation($owner, $quotation);
        };
        $issued = $devis();
        $rejected = app(DecideQuotationAction::class)->reject($owner, $devis(), 'Non');
        $accepted = $this->acceptQuotation($owner, $devis());

        $this->actingAs($owner)->get(route('quotations.show', $issued))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Quotations/Show')
                ->where('can.convert', false)->where('can.decide', true)->where('can.revise', true));
        $this->actingAs($owner)->get(route('quotations.show', $rejected))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.convert', false));
        $this->actingAs($owner)->get(route('quotations.show', $accepted))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.convert', true)->where('can.decide', true));
    }

    public function test_another_organization_cannot_convert_an_accepted_devis(): void
    {
        [$owner, $organization, $store, $variant, $warehouse] = $this->fixture();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $accepted = $this->acceptQuotation($owner, $this->issueQuotation($owner, $quotation));

        $intruder = User::factory()->create();
        $otherOrganization = $this->createOrganization($intruder);
        $this->activate($intruder, $otherOrganization, $this->createStore($otherOrganization, $intruder));

        $status = $this->actingAs($intruder)->post(route('quotations.conversion.store', $accepted), ['warehouse_id' => $warehouse->id])->status();
        $this->assertContains($status, [403, 404]);
        $this->assertSame(0, SalesOrder::query()->count());
        $this->assertSame(QuotationStatus::Accepted, $accepted->fresh()->status);
    }

    /** @return array<string, int> */
    private function sideEffectCounts(): array
    {
        return [
            'sales_orders' => SalesOrder::query()->count(),
            'sales_order_lines' => DB::table('sales_order_lines')->count(),
            'allocations' => DB::table('sales_order_inventory_allocations')->count(),
            'reservations' => InventoryReservation::query()->count(),
            'movements' => InventoryMovement::query()->count(),
            'converted_audits' => DB::table('audit_logs')->where('event', 'quotation.converted')->count(),
        ];
    }
}
