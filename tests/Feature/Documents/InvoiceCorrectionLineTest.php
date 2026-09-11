<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\AddInvoiceCorrectionLineAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Documents\RemoveInvoiceCorrectionLineAction;
use App\Actions\Documents\StartInvoiceCorrectionAction;
use App\Actions\Documents\UpdateInvoiceCorrectionLineAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DocumentSnapshotVerifier;
use App\Services\InvoiceDocumentRenderer;
use Illuminate\Validation\ValidationException;
use Tests\Support\DocumentTestCase;

/**
 * Financial-line correction: Product / Quantity / Unit price (HT) / Discount
 * editing on a post-issue correction Draft.
 *
 * These tests are NOT executed here (static validation only per the task) —
 * `php artisan test` / phpunit must not be run.
 */
class InvoiceCorrectionLineTest extends DocumentTestCase
{
    /**
     * @return array{User, Organization, Store, ProductVariant, Warehouse, Invoice, Invoice}
     *         [owner, organization, store, primaryVariant, warehouse, correctionDraft, originalIssuedInvoice]
     */
    private function correctionFixture(string $taxRate = '20.0000', string $htPrice = '1000.0000', string $qty = '2.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $tax = $this->createTaxRate($organization, 'TVA '.$taxRate, $taxRate);
        $customer = $this->createCustomer($organization, 'Client', [
            'company_name' => 'Client SARL', 'tax_identifier' => 'ICE-1', 'billing_address' => 'Rue 1',
        ]);
        $variant = $this->createProduct($organization, 'Caméra', 'CAM-1', [
            'default_sale_price' => $htPrice, 'tax_rate_id' => $tax->id,
        ])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '100.0000');

        $order = $this->createDraftOrder($owner, $organization, $store, $customer);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => $qty]);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $original = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $correction = app(StartInvoiceCorrectionAction::class)->execute($owner, $original, 'Correction financière');

        return [$owner, $organization, $store, $variant, $warehouse, $correction, $original];
    }

    private function secondVariant(Organization $organization, string $taxRate, string $htPrice, string $sku = 'CAM-2'): ProductVariant
    {
        $tax = $this->createTaxRate($organization, 'TVA '.$taxRate.' '.$sku, $taxRate);

        return $this->createProduct($organization, 'Micro '.$sku, $sku, [
            'default_sale_price' => $htPrice, 'tax_rate_id' => $tax->id,
        ])->variants->first();
    }

    public function test_correction_draft_can_change_quantity_and_server_recalculates_ht_tax_ttc(): void
    {
        [$owner, , , , , $correction] = $this->correctionFixture();
        $line = $correction->lines()->firstOrFail();

        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $line, [
            'quantity' => '3', 'discount_type' => 'none',
        ]);

        $line = $line->fresh();
        $this->assertSame('3.0000', $line->quantity);
        $this->assertSame('3000.0000', $line->subtotal_excl_tax);
        $this->assertSame('600.0000', $line->tax_amount);
        $this->assertSame('3600.0000', $line->total_incl_tax);

        $correction = $correction->fresh();
        $this->assertSame('3000.0000', $correction->subtotal_excl_tax);
        $this->assertSame('600.0000', $correction->tax_total);
        $this->assertSame('3600.0000', $correction->total_incl_tax);
    }

    public function test_correction_draft_can_change_unit_price_ht(): void
    {
        [$owner, , , , , $correction] = $this->correctionFixture();
        $line = $correction->lines()->firstOrFail();

        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $line, [
            'quantity' => '2', 'unit_price_excl_tax' => '1900', 'discount_type' => 'none',
        ]);

        $line = $line->fresh();
        $this->assertSame('1900.0000', $line->unit_price_excl_tax);
        $this->assertSame('3800.0000', $line->subtotal_excl_tax);
        $this->assertSame('760.0000', $line->tax_amount);
        $this->assertSame('4560.0000', $line->total_incl_tax);
        $this->assertSame('4560.0000', $correction->fresh()->total_incl_tax);
    }

    public function test_correction_draft_can_change_discount_percentage_and_fixed(): void
    {
        [$owner, , , , , $correction] = $this->correctionFixture();
        $line = $correction->lines()->firstOrFail();

        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $line, [
            'quantity' => '2', 'discount_type' => 'percentage', 'discount_value' => '10',
        ]);
        $line = $line->fresh();
        $this->assertSame('200.0000', $line->discount_amount); // 10% of 2000
        $this->assertSame('1800.0000', $line->taxable_amount);
        $this->assertSame('360.0000', $line->tax_amount);
        $this->assertSame('2160.0000', $line->total_incl_tax);

        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $line, [
            'quantity' => '2', 'discount_type' => 'fixed', 'discount_value' => '100',
        ]);
        $line = $line->fresh();
        $this->assertSame('100.0000', $line->discount_amount);
        $this->assertSame('1900.0000', $line->taxable_amount);
        $this->assertSame('2280.0000', $line->total_incl_tax);
    }

    public function test_correction_draft_can_add_and_remove_a_product_line(): void
    {
        [$owner, $organization, , , , $correction] = $this->correctionFixture();
        $extra = $this->secondVariant($organization, '20.0000', '500.0000');

        $added = app(AddInvoiceCorrectionLineAction::class)->execute($owner, $correction, [
            'product_variant_id' => $extra->getKey(), 'quantity' => '4', 'discount_type' => 'none',
        ]);
        $this->assertSame(2, $correction->fresh()->lines()->count());
        $this->assertSame('2000.0000', $added->subtotal_excl_tax);
        // 2400 (original line) + 2400 (4 x 500 + 20% tax) = 4800
        $this->assertSame('4800.0000', $correction->fresh()->total_incl_tax);

        app(RemoveInvoiceCorrectionLineAction::class)->execute($owner, $correction, $added);
        $this->assertSame(1, $correction->fresh()->lines()->count());
        $this->assertSame('2400.0000', $correction->fresh()->total_incl_tax);
    }

    public function test_the_last_line_of_a_correction_cannot_be_removed(): void
    {
        [$owner, , , , , $correction] = $this->correctionFixture();
        $line = $correction->lines()->firstOrFail();

        $this->expectException(ValidationException::class);
        app(RemoveInvoiceCorrectionLineAction::class)->execute($owner, $correction, $line);
    }

    public function test_a_browser_supplied_total_cannot_override_the_server_result(): void
    {
        [$owner, , , , , $correction] = $this->correctionFixture();
        $line = $correction->lines()->firstOrFail();

        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $line, [
            'quantity' => '3', 'discount_type' => 'none',
            // Hostile aggregates — ignored, never persisted.
            'subtotal_excl_tax' => '1', 'tax_total' => '1', 'total_incl_tax' => '1', 'total_ht' => '1',
        ]);

        $this->assertSame('3600.0000', $correction->fresh()->total_incl_tax);
        $this->assertSame('3000.0000', $line->fresh()->subtotal_excl_tax);
    }

    public function test_tax_rate_is_preserved_when_the_same_product_line_is_edited(): void
    {
        [$owner, , , $variant, , $correction] = $this->correctionFixture('20.0000');
        $line = $correction->lines()->firstOrFail();

        // The variant's tax config changes AFTER the correction started.
        $newRate = $this->createTaxRate($variant->organization, 'TVA 7 later', '7.0000');
        $variant->forceFill(['tax_rate_id' => $newRate->getKey()])->save();

        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $line, [
            'quantity' => '5', 'discount_type' => 'none',
        ]);

        // Snapshot tax rate untouched — only qty changed.
        $this->assertSame('20.0000', $line->fresh()->tax_rate);
        $this->assertSame('1000.0000', $line->fresh()->tax_amount); // 5 x 1000 x 20%
    }

    public function test_tax_is_reresolved_when_the_product_is_replaced_and_is_never_hardcoded(): void
    {
        [$owner, $organization, , , , $correction] = $this->correctionFixture('20.0000');
        $line = $correction->lines()->firstOrFail();
        $replacement = $this->secondVariant($organization, '14.0000', '800.0000');

        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $line, [
            'quantity' => '2', 'discount_type' => 'none', 'product_variant_id' => $replacement->getKey(),
        ]);

        $line = $line->fresh();
        $this->assertSame($replacement->getKey(), (int) $line->product_variant_id);
        $this->assertNull($line->sales_order_line_id); // stale source link dropped
        $this->assertSame('14.0000', $line->tax_rate);  // re-resolved, not 20, not hardcoded
        $this->assertSame('800.0000', $line->unit_price_excl_tax); // suggested from the new variant
        $this->assertSame('1600.0000', $line->subtotal_excl_tax);
        $this->assertSame('224.0000', $line->tax_amount); // 1600 x 14%
        $this->assertStringContainsString('14', (string) $line->tax_name);
    }

    public function test_editing_a_correction_line_never_touches_the_original_invoice(): void
    {
        [$owner, , , , , $correction, $original] = $this->correctionFixture();
        $originalLine = $original->lines()->firstOrFail()->only([
            'quantity', 'unit_price_excl_tax', 'discount_amount', 'tax_amount', 'total_incl_tax', 'tax_rate',
        ]);
        $originalTotals = $original->only(['subtotal_excl_tax', 'discount_total', 'tax_total', 'total_incl_tax', 'invoice_number', 'status']);

        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $correction->lines()->firstOrFail(), [
            'quantity' => '9', 'unit_price_excl_tax' => '5', 'discount_type' => 'fixed', 'discount_value' => '1',
        ]);

        $this->assertSame($originalLine, $original->fresh()->lines()->firstOrFail()->only(array_keys($originalLine)));
        $this->assertSame($originalTotals, $original->fresh()->only(array_keys($originalTotals)));
        $this->assertSame(InvoiceStatus::Issued, $original->fresh()->status);
    }

    public function test_a_later_catalogue_price_change_does_not_mutate_an_existing_correction_snapshot(): void
    {
        [$owner, $organization, , , , $correction] = $this->correctionFixture();
        $extra = $this->secondVariant($organization, '20.0000', '500.0000');
        $added = app(AddInvoiceCorrectionLineAction::class)->execute($owner, $correction, [
            'product_variant_id' => $extra->getKey(), 'quantity' => '1', 'discount_type' => 'none',
        ]);

        $extra->forceFill(['default_sale_price' => '9999.0000', 'unit_price_ht' => '9999.0000'])->save();

        $this->assertSame('500.0000', $added->fresh()->unit_price_excl_tax);
        $this->assertSame('600.0000', $added->fresh()->total_incl_tax);
    }

    public function test_normal_invoice_verification_still_enforces_the_sales_order_snapshot(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $invoice = $this->createInvoice($owner, $order); // plain, non-correction draft

        // Tamper a line so it no longer matches the authoritative Order snapshot.
        InvoiceLine::query()->whereKey($invoice->lines()->firstOrFail()->getKey())->update(['quantity' => '999.0000']);

        $this->expectException(ValidationException::class);
        app(DocumentSnapshotVerifier::class)->verifyInvoice($invoice->fresh());
    }

    public function test_correction_verification_allows_an_intentional_line_difference_from_the_order(): void
    {
        [$owner, , , , , $correction] = $this->correctionFixture();

        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $correction->lines()->firstOrFail(), [
            'quantity' => '7', 'unit_price_excl_tax' => '123', 'discount_type' => 'percentage', 'discount_value' => '5',
        ]);

        // No exception: the correction reconciles with its OWN line snapshots,
        // even though it now differs from the Sales Order.
        app(DocumentSnapshotVerifier::class)->verifyInvoice($correction->fresh());
        $this->assertTrue(true);
    }

    public function test_malformed_line_arithmetic_blocks_issuance_of_a_correction(): void
    {
        [$owner, , , , , $correction] = $this->correctionFixture();
        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $correction->lines()->firstOrFail(), [
            'quantity' => '2', 'discount_type' => 'none',
        ]);

        // Corrupt the persisted line total behind the action's back.
        InvoiceLine::query()->whereKey($correction->lines()->firstOrFail()->getKey())->update(['total_incl_tax' => '999999.0000']);

        $this->expectException(ValidationException::class);
        app(IssueInvoiceAction::class)->execute($owner, $correction->fresh());
    }

    public function test_a_recalculated_correction_can_be_issued_and_supersedes_the_original(): void
    {
        [$owner, , , , , $correction, $original] = $this->correctionFixture();
        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $correction->lines()->firstOrFail(), [
            'quantity' => '3', 'unit_price_excl_tax' => '1900', 'discount_type' => 'percentage', 'discount_value' => '10',
        ]);

        $issued = app(IssueInvoiceAction::class)->execute($owner, $correction->fresh())->fresh();

        $this->assertSame(InvoiceStatus::Issued, $issued->status);
        $this->assertNotNull($issued->invoice_number);
        $this->assertNotSame($original->invoice_number, $issued->invoice_number);
        $this->assertSame(InvoiceStatus::Superseded, $original->fresh()->status);

        // 3 x 1900 = 5700 ; -10% = 5130 ; +20% = 6156
        $this->assertSame('6156.0000', $issued->total_incl_tax);
    }

    public function test_the_corrected_pdf_renders_from_the_corrected_lines(): void
    {
        [$owner, $organization, , , , $correction] = $this->correctionFixture();
        $replacement = $this->secondVariant($organization, '20.0000', '1234.0000', 'SM7C');

        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $correction->lines()->firstOrFail(), [
            'quantity' => '3', 'discount_type' => 'none', 'product_variant_id' => $replacement->getKey(),
        ]);

        $html = app(InvoiceDocumentRenderer::class)->html($correction->fresh());
        $this->assertStringContainsString('Micro SM7C', $html);
        $this->assertStringContainsString('1 234,00', $html);
    }

    public function test_remise_pdf_column_is_conditional_on_a_correction_line_discount(): void
    {
        [$owner, , , , , $correction] = $this->correctionFixture();
        $renderer = app(InvoiceDocumentRenderer::class);

        $this->assertFalse($renderer->payload($correction->fresh())['has_discount']);

        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $correction->lines()->firstOrFail(), [
            'quantity' => '2', 'discount_type' => 'percentage', 'discount_value' => '5',
        ]);

        $this->assertTrue($renderer->payload($correction->fresh())['has_discount']);
    }

    public function test_line_editing_creates_no_inventory_movement_or_reservation_change(): void
    {
        [$owner, $organization, , $variant, , $correction] = $this->correctionFixture();
        $movementsBefore = $variant->inventoryMovements()->count();
        $reservationsBefore = $variant->inventoryReservations()->get()->map->only(['id', 'quantity', 'status'])->toArray();
        $balancesBefore = $variant->inventoryBalances()->get()->map->only(['id', 'on_hand', 'reserved', 'available'])->toArray();

        $extra = $this->secondVariant($organization, '20.0000', '500.0000');
        $added = app(AddInvoiceCorrectionLineAction::class)->execute($owner, $correction, [
            'product_variant_id' => $extra->getKey(), 'quantity' => '3', 'discount_type' => 'none',
        ]);
        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $correction->lines()->firstOrFail(), [
            'quantity' => '10', 'discount_type' => 'none',
        ]);
        app(RemoveInvoiceCorrectionLineAction::class)->execute($owner, $correction, $added);

        $this->assertSame($movementsBefore, $variant->fresh()->inventoryMovements()->count());
        $this->assertSame($reservationsBefore, $variant->fresh()->inventoryReservations()->get()->map->only(['id', 'quantity', 'status'])->toArray());
        $this->assertSame($balancesBefore, $variant->fresh()->inventoryBalances()->get()->map->only(['id', 'on_hand', 'reserved', 'available'])->toArray());
    }

    public function test_line_editing_does_not_mutate_existing_payments(): void
    {
        [$owner, $organization, , , , $correction] = $this->correctionFixture();
        $account = $this->createFinancialAccount($organization);
        $order = $correction->salesOrder;
        $payment = $this->recordPayment($owner, $order, $account, '1000.0000');
        $before = $payment->fresh()->only(['amount', 'payment_date', 'status', 'method']);
        $allocationsBefore = $payment->allocations()->get()->map->only(['id', 'amount', 'sales_order_id'])->toArray();

        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $correction->lines()->firstOrFail(), [
            'quantity' => '3', 'unit_price_excl_tax' => '50', 'discount_type' => 'none',
        ]);

        $this->assertSame($before, $payment->fresh()->only(array_keys($before)));
        $this->assertSame($allocationsBefore, $payment->fresh()->allocations()->get()->map->only(['id', 'amount', 'sales_order_id'])->toArray());
    }

    // ----- HTTP surface: authorization, tenancy, IDOR, read-only invoices -----

    public function test_issued_and_superseded_invoices_reject_line_mutations(): void
    {
        [$owner, , , $variant, , $correction, $original] = $this->correctionFixture();
        app(IssueInvoiceAction::class)->execute($owner, $correction->fresh()); // original -> superseded

        foreach ([$original->fresh(), $correction->fresh()] as $readOnly) {
            $this->assertFalse($owner->can('editLines', $readOnly));
            $line = $readOnly->lines()->firstOrFail();
            $this->actingAs($owner)
                ->patch(route('invoices.correction-lines.update', [$readOnly, $line]), ['quantity' => '2', 'discount_type' => 'none'])
                ->assertForbidden();
        }
    }

    public function test_a_plain_order_sourced_draft_invoice_cannot_use_the_correction_line_editor(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $draft = $this->createInvoice($owner, $order);
        $line = $draft->lines()->firstOrFail();

        $this->assertFalse($owner->can('editLines', $draft));
        $this->actingAs($owner)
            ->patch(route('invoices.correction-lines.update', [$draft, $line]), ['quantity' => '2', 'discount_type' => 'none'])
            ->assertForbidden();
    }

    public function test_a_foreign_tenant_cannot_reach_a_correction_line(): void
    {
        [, , , , , $correction] = $this->correctionFixture();
        $line = $correction->lines()->firstOrFail();

        $outsider = User::factory()->create();
        $otherOrg = $this->createOrganization($outsider);
        $otherStore = $this->createStore($otherOrg, $outsider);
        $this->activate($outsider, $otherOrg, $otherStore);

        $this->actingAs($outsider)
            ->patch(route('invoices.correction-lines.update', [$correction, $line]), ['quantity' => '2', 'discount_type' => 'none'])
            ->assertNotFound();
    }

    public function test_a_line_from_another_invoice_cannot_be_mutated_through_this_correction(): void
    {
        [$owner, $organization, $store, $variant, $warehouse, $correctionA] = $this->correctionFixture();

        // A second, independent correction in the same tenant/store.
        $customerB = $this->createCustomer($organization, 'Client B');
        $orderB = $this->createDraftOrder($owner, $organization, $store, $customerB);
        $this->addCatalogLine($owner, $orderB, $variant, $warehouse, ['quantity' => '1.0000']);
        $orderB = app(ConfirmSalesOrderAction::class)->execute($owner, $orderB)->fresh();
        $originalB = $this->issueInvoice($owner, $this->createInvoice($owner, $orderB));
        $correctionB = app(StartInvoiceCorrectionAction::class)->execute($owner, $originalB, 'B');
        $lineB = $correctionB->lines()->firstOrFail();

        $this->actingAs($owner)
            ->patch(route('invoices.correction-lines.update', [$correctionA->id, $lineB->id]), ['quantity' => '2', 'discount_type' => 'none'])
            ->assertNotFound();

        $this->assertSame('1.0000', $lineB->fresh()->quantity);
    }

    public function test_a_role_without_update_draft_permission_is_blocked(): void
    {
        [$owner, $organization, $store, $variant, $warehouse, $correction] = $this->correctionFixture();
        $line = $correction->lines()->firstOrFail();

        $clerk = User::factory()->create();
        $this->addOrganizationMember($organization, $clerk, ['invoices.view'], roleName: 'Clerk');
        $this->addStoreMember($store, $clerk);
        $this->activate($clerk, $organization, $store);

        $this->actingAs($clerk)
            ->patch(route('invoices.correction-lines.update', [$correction, $line]), ['quantity' => '2', 'discount_type' => 'none'])
            ->assertForbidden();

        $this->actingAs($clerk)
            ->post(route('invoices.correction-lines.store', $correction), ['product_variant_id' => $variant->getKey(), 'quantity' => '1', 'discount_type' => 'none'])
            ->assertForbidden();
    }

    public function test_the_search_endpoint_only_returns_active_catalogue_variants_for_the_tenant(): void
    {
        [$owner, $organization, , $variant, , $correction] = $this->correctionFixture();

        $this->actingAs($owner)
            ->getJson(route('invoices.correction-lines.search', $correction).'?search=CAM')
            ->assertOk()
            ->assertJsonPath('data.0.id', $variant->getKey())
            ->assertJsonPath('data.0.tax_rate', '20.0000');
    }

    public function test_concurrent_style_sequential_edits_never_leave_stale_totals(): void
    {
        [$owner, , , , , $correction] = $this->correctionFixture();
        $line = $correction->lines()->firstOrFail();

        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $line->fresh(), ['quantity' => '3', 'discount_type' => 'none']);
        app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $correction, $line->fresh(), ['quantity' => '5', 'discount_type' => 'none']);

        // Totals are always fully reconciled from lines, never incrementally patched.
        $this->assertSame('5000.0000', $correction->fresh()->subtotal_excl_tax);
        $this->assertSame('6000.0000', $correction->fresh()->total_incl_tax);
        app(DocumentSnapshotVerifier::class)->verifyInvoice($correction->fresh());
    }
}
