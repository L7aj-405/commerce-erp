<?php

namespace App\Services\SupplierInvoices;

use App\Enums\SupplierInvoiceCostNature;
use App\Enums\SupplierInvoiceMatchStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierInvoice;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, live three-way style matching of a supplier invoice against its purchase order. It never blocks:
 * the result only tells approval whether an acknowledgement is required.
 *
 * Per inventory line flags: not_linked (no PO line), over_invoiced (approved invoiced qty of the PO line + this
 * line > ordered), not_received (invoiced > received), po_price_missing, price_variance (unit price differs from
 * the PO line price). Expense lines are never matched against a PO.
 *
 * Status: no_po (no purchase order), variance (any over_invoiced / price_variance), po_matched (every inventory line
 * linked and unflagged), po_partial (otherwise: not linked / not received / PO price missing).
 */
final class SupplierInvoiceMatchingService
{
    /** @return array{status: string, lines: list<array{line_id: int, flags: list<string>}>} */
    public function match(SupplierInvoice $invoice): array
    {
        $invoice->loadMissing('lines');
        if ($invoice->purchase_order_id === null) {
            return [
                'status' => SupplierInvoiceMatchStatus::NoPo->value,
                'lines' => $invoice->lines->map(fn ($line) => ['line_id' => $line->id, 'flags' => []])->values()->all(),
            ];
        }

        $poLineIds = $invoice->lines->pluck('purchase_order_line_id')->filter()->unique()->values()->all();
        $poLines = PurchaseOrderLine::query()->where('organization_id', $invoice->organization_id)
            ->where('purchase_order_id', $invoice->purchase_order_id)->whereIn('id', $poLineIds)->get()->keyBy('id');
        $others = $this->invoicedByOthers($invoice, $poLineIds);

        $own = [];
        foreach ($invoice->lines as $line) {
            if ($line->purchase_order_line_id !== null) {
                $own[$line->purchase_order_line_id] = Decimal::add($own[$line->purchase_order_line_id] ?? '0', (string) $line->quantity);
            }
        }

        $rows = [];
        $variance = $partial = false;
        foreach ($invoice->lines as $line) {
            $flags = [];
            if ($line->cost_nature === SupplierInvoiceCostNature::Inventory) {
                $poLine = $line->purchase_order_line_id === null ? null : $poLines->get($line->purchase_order_line_id);
                if ($poLine === null) {
                    $flags[] = 'not_linked';
                } else {
                    $invoiced = Decimal::add($others[$poLine->id] ?? '0', $own[$poLine->id]);
                    if (Decimal::compare($invoiced, (string) $poLine->quantity) > 0) {
                        $flags[] = 'over_invoiced';
                    }
                    if (Decimal::compare($invoiced, (string) $poLine->received_quantity) > 0) {
                        $flags[] = 'not_received';
                    }
                    if ($poLine->unit_price === null) {
                        $flags[] = 'po_price_missing';
                    } elseif (Decimal::compare((string) $line->unit_price_ht, (string) $poLine->unit_price) !== 0) {
                        $flags[] = 'price_variance';
                    }
                }
            }
            $variance = $variance || array_intersect($flags, ['over_invoiced', 'price_variance']) !== [];
            $partial = $partial || $flags !== [];
            $rows[] = ['line_id' => $line->id, 'flags' => $flags];
        }

        return [
            'status' => ($variance ? SupplierInvoiceMatchStatus::Variance : ($partial ? SupplierInvoiceMatchStatus::PoPartial : SupplierInvoiceMatchStatus::PoMatched))->value,
            'lines' => $rows,
        ];
    }

    public function status(SupplierInvoice $invoice): string
    {
        if ($invoice->purchase_order_id === null) {
            return SupplierInvoiceMatchStatus::NoPo->value;
        }

        return $this->match($invoice)['status'];
    }

    /**
     * Quantity already invoiced on OTHER approved invoices, per purchase order line.
     *
     * @param  list<int>  $poLineIds
     * @return array<int, string>
     */
    private function invoicedByOthers(SupplierInvoice $invoice, array $poLineIds): array
    {
        if ($poLineIds === []) {
            return [];
        }

        return DB::table('supplier_invoice_lines as l')
            ->join('supplier_invoices as i', fn ($join) => $join->on('i.id', '=', 'l.supplier_invoice_id')->on('i.organization_id', '=', 'l.organization_id'))
            ->where('l.organization_id', $invoice->organization_id)
            ->where('i.status', SupplierInvoiceStatus::Approved->value)
            ->where('i.id', '!=', $invoice->getKey())
            ->whereIn('l.purchase_order_line_id', $poLineIds)
            ->groupBy('l.purchase_order_line_id')
            ->selectRaw('l.purchase_order_line_id as po_line_id, SUM(l.quantity) as qty')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->po_line_id => Decimal::normalize((string) $row->qty)])
            ->all();
    }
}
