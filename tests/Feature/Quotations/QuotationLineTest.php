<?php

namespace Tests\Feature\Quotations;

use App\Actions\Quotations\RemoveQuotationLineAction;
use App\Actions\Quotations\SaveQuotationLineAction;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\QuotationDocumentRenderer;
use Tests\Support\QuotationTestCase;

class QuotationLineTest extends QuotationTestCase
{
    /** @return array{User, Organization, Store} */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);

        return [$owner, $organization, $store];
    }

    public function test_a_catalogue_line_snapshots_identity_price_and_tax(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Enceinte', 'ENC-1', ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $quotation = $this->createQuotation($owner, $organization, $store);

        $line = $this->addCatalogQuotationLine($owner, $quotation, $variant, ['quantity' => '3']);

        $this->assertSame($variant->getKey(), (int) $line->product_variant_id);
        $this->assertSame('Enceinte', $line->product_name);
        $this->assertSame('1000.0000', $line->unit_price_excl_tax);
        $this->assertSame('1200.0000', $line->unit_price_incl_tax);
        $this->assertSame('20.0000', $line->tax_rate);
        $this->assertSame('3000.0000', $line->subtotal_excl_tax);
        $this->assertSame('600.0000', $line->tax_amount);
        $this->assertSame('3600.0000', $line->total_incl_tax);
        $this->assertSame('3600.0000', $quotation->fresh()->total_incl_tax);
    }

    public function test_ttc_only_product_derives_ht_with_exact_decimal_math(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        // public_price_ttc set, no explicit HT -> resolver derives HT = TTC / 1.2
        $variant = $this->createProduct($organization, 'TTC only', 'TTC-1', ['public_price_ttc' => '120.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $quotation = $this->createQuotation($owner, $organization, $store);

        $line = $this->addCatalogQuotationLine($owner, $quotation, $variant);

        $this->assertSame('100.0000', $line->unit_price_excl_tax); // exact: 120 * 100 / 120
        $this->assertSame('120.0000', $line->unit_price_incl_tax);
        $this->assertSame('20.0000', $line->tax_amount);
    }

    public function test_ht_entry_mode_derives_ttc_and_ttc_entry_mode_derives_ht(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Overridable', 'OVR-1', ['default_sale_price' => '500.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $quotation = $this->createQuotation($owner, $organization, $store);

        $htLine = $this->addCatalogQuotationLine($owner, $quotation, $variant, ['price_input_mode' => 'ht', 'unit_price' => '900', 'quantity' => '1']);
        $this->assertSame('900.0000', $htLine->unit_price_excl_tax);
        $this->assertSame('1080.0000', $htLine->unit_price_incl_tax);

        $ttcLine = app(SaveQuotationLineAction::class)->execute($owner, $quotation, [
            'line_type' => 'catalog', 'product_variant_id' => $variant->getKey(),
            'price_input_mode' => 'ttc', 'unit_price' => '1200', 'quantity' => '1',
            'discount_type' => 'none', 'discount_value' => '0',
        ], $htLine);
        $this->assertSame('1000.0000', $ttcLine->unit_price_excl_tax); // 1200 / 1.2
        $this->assertSame('1200.0000', $ttcLine->unit_price_incl_tax);
    }

    public function test_tax_rate_is_never_hardcoded_and_multi_tax_is_supported(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $tax7 = $this->createTaxRate($organization, 'TVA 7', '7.0000');
        $tax14 = $this->createTaxRate($organization, 'TVA 14', '14.0000');
        $v7 = $this->createProduct($organization, 'Sept', 'S-7', ['default_sale_price' => '100.0000', 'tax_rate_id' => $tax7->id])->variants->first();
        $v14 = $this->createProduct($organization, 'Quatorze', 'Q-14', ['default_sale_price' => '200.0000', 'tax_rate_id' => $tax14->id])->variants->first();
        $quotation = $this->createQuotation($owner, $organization, $store);

        $l7 = $this->addCatalogQuotationLine($owner, $quotation, $v7, ['quantity' => '1']);
        $l14 = $this->addCatalogQuotationLine($owner, $quotation, $v14, ['quantity' => '1']);

        $this->assertSame('7.0000', $l7->tax_rate);
        $this->assertSame('7.0000', $l7->tax_amount); // 7% of 100
        $this->assertSame('14.0000', $l14->tax_rate);
        $this->assertSame('28.0000', $l14->tax_amount); // 14% of 200

        $quotation = $quotation->fresh();
        $this->assertSame('300.0000', $quotation->subtotal_excl_tax);
        $this->assertSame('35.0000', $quotation->tax_total); // 7 + 28, not one blended 20%
        $this->assertSame('335.0000', $quotation->total_incl_tax);

        $payload = app(QuotationDocumentRenderer::class)->payload($quotation);
        $this->assertCount(2, $payload['tax_lines']); // grouped per rate
    }

    public function test_percentage_and_fixed_discount_reuse_the_shared_calculator(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Disc', 'D-1', ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $quotation = $this->createQuotation($owner, $organization, $store);

        $line = $this->addCatalogQuotationLine($owner, $quotation, $variant, ['quantity' => '2', 'discount_type' => 'percentage', 'discount_value' => '10']);
        $this->assertSame('200.0000', $line->discount_amount); // 10% of 2000
        $this->assertSame('1800.0000', $line->taxable_amount);
        $this->assertSame('360.0000', $line->tax_amount);
        $this->assertSame('2160.0000', $line->total_incl_tax);

        $line = app(SaveQuotationLineAction::class)->execute($owner, $quotation, [
            'line_type' => 'catalog', 'product_variant_id' => $variant->getKey(),
            'quantity' => '2', 'discount_type' => 'fixed', 'discount_value' => '150',
        ], $line);
        $this->assertSame('150.0000', $line->discount_amount);
        $this->assertSame('1850.0000', $line->taxable_amount);
    }

    public function test_totals_are_recomputed_server_side_and_browser_totals_are_ignored(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Srv', 'SRV-1', ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $quotation = $this->createQuotation($owner, $organization, $store);

        app(SaveQuotationLineAction::class)->execute($owner, $quotation, [
            'line_type' => 'catalog', 'product_variant_id' => $variant->getKey(), 'quantity' => '2',
            'discount_type' => 'none', 'discount_value' => '0',
            // hostile fields — never persisted
            'subtotal_excl_tax' => '1', 'tax_amount' => '1', 'total_incl_tax' => '1', 'unit_price_incl_tax' => '5',
        ]);

        $quotation = $quotation->fresh();
        $this->assertSame('2000.0000', $quotation->subtotal_excl_tax);
        $this->assertSame('400.0000', $quotation->tax_total);
        $this->assertSame('2400.0000', $quotation->total_incl_tax);
    }

    public function test_add_change_and_remove_lines_keep_totals_reconciled(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Item', 'IT-1', ['default_sale_price' => '500.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $quotation = $this->createQuotation($owner, $organization, $store);

        $a = $this->addCatalogQuotationLine($owner, $quotation, $variant, ['quantity' => '1']);
        $b = $this->addNonStockQuotationLine($owner, $quotation, ['tax_rate_id' => $tax->id, 'unit_price' => '1000', 'quantity' => '2']);
        $this->assertSame(2, $quotation->fresh()->lines()->count());
        // 600 (1x500 +20%) + 2400 (2x1000 +20%)
        $this->assertSame('3000.0000', $quotation->fresh()->total_incl_tax);

        app(SaveQuotationLineAction::class)->execute($owner, $quotation, [
            'line_type' => 'catalog', 'product_variant_id' => $variant->getKey(), 'quantity' => '4',
            'discount_type' => 'none', 'discount_value' => '0',
        ], $a);
        $this->assertSame('4800.0000', $quotation->fresh()->total_incl_tax); // 2400 + 2400

        app(RemoveQuotationLineAction::class)->execute($owner, $quotation, $b);
        $this->assertSame(1, $quotation->fresh()->lines()->count());
        $this->assertSame('2400.0000', $quotation->fresh()->total_incl_tax);
    }

    public function test_line_idor_is_blocked_between_two_quotations(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'X', 'X-1', ['default_sale_price' => '100.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $qa = $this->createQuotation($owner, $organization, $store);
        $qb = $this->createQuotation($owner, $organization, $store);
        $lineB = $this->addCatalogQuotationLine($owner, $qb, $variant);

        $this->actingAs($owner)
            ->patch(route('quotations.lines.update', [$qa->id, $lineB->id]), ['line_type' => 'catalog', 'product_variant_id' => $variant->id, 'quantity' => '9', 'discount_type' => 'none'])
            ->assertNotFound();

        $this->assertSame('1.0000', $lineB->fresh()->quantity);
    }
}
