<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\AddInvoiceCorrectionLineAction;
use App\Actions\Documents\RemoveInvoiceCorrectionLineAction;
use App\Actions\Documents\UpdateInvoiceCorrectionLineAction;
use App\Actions\Documents\UpdateInvoiceDraftAction;
use App\Models\Invoice;
use Illuminate\Validation\ValidationException;
use Tests\Support\DocumentTestCase;

class InvoiceCorrectionLineTest extends DocumentTestCase
{
    public function test_invoice_financial_lines_are_read_only_and_crafted_http_requests_fail_closed(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $invoice = $this->createInvoice($owner, $order);
        $line = $invoice->lines()->firstOrFail();
        $before = $line->only(['quantity', 'unit_price_excl_tax', 'discount_amount', 'tax_amount', 'total_incl_tax']);

        $this->assertFalse($owner->can('editLines', $invoice));

        $this->actingAs($owner)
            ->post(route('invoices.correction-lines.store', $invoice), [
                'product_variant_id' => 999999,
                'quantity' => '999.0000',
                'discount_type' => 'none',
            ])->assertForbidden();
        $this->actingAs($owner)
            ->patch(route('invoices.correction-lines.update', [$invoice, $line]), [
                'quantity' => '999.0000',
                'unit_price_excl_tax' => '0.0100',
                'discount_type' => 'none',
            ])->assertForbidden();
        $this->actingAs($owner)
            ->delete(route('invoices.correction-lines.destroy', [$invoice, $line]))
            ->assertForbidden();
        $this->actingAs($owner)
            ->getJson(route('invoices.correction-lines.search', $invoice).'?search=test')
            ->assertForbidden();

        $this->assertSame($before, $line->fresh()->only(array_keys($before)));
        $this->assertSame('100.0000', $invoice->fresh()->total_incl_tax);
    }

    public function test_direct_domain_action_calls_cannot_bypass_invoice_line_protection(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $invoice = $this->createInvoice($owner, $order);
        $line = $invoice->lines()->firstOrFail();

        foreach ([
            fn () => app(AddInvoiceCorrectionLineAction::class)->execute($owner, $invoice, [
                'product_variant_id' => 999999, 'quantity' => '1', 'discount_type' => 'none',
            ]),
            fn () => app(UpdateInvoiceCorrectionLineAction::class)->execute($owner, $invoice, $line, [
                'quantity' => '2', 'discount_type' => 'none',
            ]),
            fn () => app(RemoveInvoiceCorrectionLineAction::class)->execute($owner, $invoice, $line),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Direct Invoice financial-line mutation must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('invoice', $exception->errors());
            }
        }

        $this->assertSame('1.0000', $line->fresh()->quantity);
        $this->assertSame('100.0000', $invoice->fresh()->total_incl_tax);
    }

    public function test_safe_draft_metadata_can_change_without_recalculating_commercial_values(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $invoice = $this->createInvoice($owner, $order);
        $financialBefore = $invoice->only(['subtotal_excl_tax', 'discount_total', 'tax_total', 'total_incl_tax']);
        $linesBefore = $invoice->lines()->get()->map->only(['id', 'quantity', 'unit_price_excl_tax', 'total_incl_tax'])->all();

        app(UpdateInvoiceDraftAction::class)->execute($owner, $invoice, [
            'invoice_date' => '2026-06-01',
            'representative_name' => 'Conseiller facturation',
            'payment_method_summary' => 'Virement',
            'notes' => 'Mention documentaire uniquement.',
        ]);

        $this->assertSame('Conseiller facturation', $invoice->fresh()->representative_name);
        $this->assertSame('Mention documentaire uniquement.', $invoice->fresh()->notes);
        $this->assertSame($financialBefore, $invoice->fresh()->only(array_keys($financialBefore)));
        $this->assertSame($linesBefore, $invoice->fresh()->lines()->get()->map->only(['id', 'quantity', 'unit_price_excl_tax', 'total_incl_tax'])->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'invoice.draft_updated', 'auditable_id' => $invoice->id]);
    }

    public function test_legacy_correction_documents_remain_renderable_but_read_only(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $original = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $legacy = $original->replicate(['invoice_number', 'issued_at', 'issued_by_user_id']);
        $legacy->status = 'draft';
        $legacy->version = 2;
        $legacy->corrected_invoice_id = $original->id;
        $legacy->correction_reason = 'Document historique antérieur';
        $legacy->save();
        foreach ($original->lines as $sourceLine) {
            $copied = $sourceLine->replicate();
            $copied->invoice_id = $legacy->id;
            $copied->save();
        }

        $this->actingAs($owner)->get(route('invoices.show', $legacy))->assertOk();
        $this->actingAs($owner)->get(route('invoices.print', $legacy))->assertOk();
        $this->assertFalse($owner->can('editLines', $legacy));
        $this->assertSame(1, Invoice::query()->where('corrected_invoice_id', $original->id)->count());
    }
}
