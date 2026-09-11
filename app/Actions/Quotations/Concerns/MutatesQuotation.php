<?php

namespace App\Actions\Quotations\Concerns;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

trait MutatesQuotation
{
    use AuthorizesQuotationAction;

    /** Re-read the Devis under a row lock and prove it is still an editable draft. */
    private function lockDraft(Quotation $quotation): Quotation
    {
        $locked = Quotation::query()
            ->where('organization_id', $quotation->organization_id)
            ->where('store_id', $quotation->store_id)
            ->whereKey($quotation->getKey())
            ->lockForUpdate()
            ->with(['lines', 'organization', 'store'])
            ->firstOrFail();

        if (! $locked->status->isEditable()) {
            throw ValidationException::withMessages(['quotation' => 'Seul un devis brouillon peut être modifié.']);
        }

        return $locked;
    }

    private function lockLine(Quotation $quotation, QuotationLine $line): QuotationLine
    {
        return QuotationLine::query()
            ->where('organization_id', $quotation->organization_id)
            ->where('quotation_id', $quotation->getKey())
            ->whereKey($line->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** Recompute the Devis aggregates strictly from its persisted lines. */
    private function reconcileTotals(Quotation $quotation): void
    {
        $quotation->load('lines');

        $subtotal = '0.0000';
        $discount = '0.0000';
        $tax = '0.0000';
        $total = '0.0000';
        foreach ($quotation->lines as $line) {
            $subtotal = Decimal::add($subtotal, $line->subtotal_excl_tax);
            $discount = Decimal::add($discount, $line->discount_amount);
            $tax = Decimal::add($tax, $line->tax_amount);
            $total = Decimal::add($total, $line->total_incl_tax);
        }

        $quotation->subtotal_excl_tax = $subtotal;
        $quotation->discount_total = $discount;
        $quotation->tax_total = $tax;
        $quotation->total_incl_tax = $total;
        $quotation->save();
    }

    private function assertHasLines(Quotation $quotation): void
    {
        if ($quotation->lines()->count() === 0) {
            throw ValidationException::withMessages(['lines' => 'Un devis doit contenir au moins une ligne.']);
        }
    }

    private function markExpiredIfPastValidity(Quotation $quotation): void
    {
        if ($quotation->status === QuotationStatus::Issued && $quotation->isPastValidity()) {
            $quotation->status = QuotationStatus::Expired;
            $quotation->save();
        }
    }
}
