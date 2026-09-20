<?php

namespace Tests\Feature\Quotations;

use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderLineType;
use App\Enums\SalesOrderStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;
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

    public function test_an_issued_devis_converts_into_a_draft_sales_order_without_retyping_lines(): void
    {
        [$owner, $organization, $store, $variant, $warehouse] = $this->fixture();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant, ['quantity' => '3']);
        $this->addNonStockQuotationLine($owner, $quotation, ['name' => 'Installation', 'unit_price' => '800', 'quantity' => '1']);
        $issued = $this->issueQuotation($owner, $quotation);

        $result = app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $issued, ['warehouse_id' => $warehouse->id]);
        $order = $result['order'];

        $this->assertSame(SalesOrderStatus::Draft, $order->status);
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

        $first = app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $issued, ['warehouse_id' => $warehouse->id]);
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

        $order = app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $issued, ['warehouse_id' => $warehouse->id])['order'];
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

        $result = app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $issued, ['warehouse_id' => $warehouse->id]);

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
}
