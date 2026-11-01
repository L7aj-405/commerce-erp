<?php

namespace App\Services\Pos;

use App\Enums\QuotationSupplierAvailabilityStatus as Status;
use App\Enums\SalesOrderLineType;
use App\Enums\SupplierProcurementStatus;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesOrderProcurement;
use App\Services\QuotationSourcing;
use App\Support\Decimal;

/**
 * Supplier sourcing view of a POS cart ("Articles à sourcer").
 *
 * The cart is a draft POS Sales Order. For every catalogue line: how much free
 * company stock covers it NOW, the missing quantity, and the supplier
 * availability evidence gathered for it. The gate (pending / available /
 * partial / unavailable, confirmed quantity as the source of truth, partial
 * answers never enough on their own) is QuotationSourcing::evaluateRows — the
 * exact rules of the Devis, not a copy.
 *
 * Everything is recomputed from current stock, never from the cart's old
 * snapshot. Supplier evidence is not stock, not a reservation and not a
 * purchase order. A legacy special-order requirement already raised on the line
 * (SalesOrderProcurement, POS V1) still covers its confirmed quantity, so the
 * same need is never sourced twice.
 */
final class PosCartSourcing
{
    public function __construct(private readonly QuotationSourcing $sourcing) {}

    /**
     * @return array{lines: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function evaluate(SalesOrder $cart, bool $lockRequests = false): array
    {
        $lines = SalesOrderLine::query()
            ->where('organization_id', $cart->organization_id)
            ->where('sales_order_id', $cart->getKey())
            ->where('line_type', SalesOrderLineType::Catalog->value)
            ->whereNotNull('product_variant_id')
            ->with('procurements')
            ->orderBy('position')->orderBy('id')
            ->get();

        $requests = QuotationSupplierAvailabilityRequest::query()
            ->where('organization_id', $cart->organization_id)
            ->where('sales_order_id', $cart->getKey())
            ->where('status', '!=', Status::Cancelled->value)
            ->with(['supplier:id,name', 'requestedBy:id,name', 'resolvedBy:id,name'])
            ->orderBy('id')
            ->when($lockRequests, fn ($query) => $query->lockForUpdate())
            ->get()
            ->groupBy('sales_order_line_id');

        $legacyCovered = [];
        $rows = [];
        foreach ($lines as $line) {
            $legacy = $line->procurements
                ->filter(fn (SalesOrderProcurement $p) => $p->status->coversSalesLine())
                ->reduce(fn (string $sum, SalesOrderProcurement $p) => Decimal::add($sum, (string) $p->quantity), '0.0000');
            $quantity = Decimal::subtract((string) $line->quantity, $legacy);
            $legacyCovered[$line->getKey()] = $legacy;
            $rows[] = [
                'line_id' => (int) $line->getKey(),
                'line_type' => 'catalog',
                'is_catalog' => true,
                'product_variant_id' => (int) $line->product_variant_id,
                'non_stock_item_id' => null,
                'quantity' => Decimal::compare($quantity, '0') > 0 ? $quantity : '0.0000',
                'article' => trim($line->product_name.($line->variant_name ? ' — '.$line->variant_name : '')),
                'reference' => $line->reference ?: $line->sku,
            ];
        }

        $available = $this->sourcing->availableByVariant((int) $cart->organization_id, $lines->pluck('product_variant_id')->all());
        $evaluation = $this->sourcing->evaluateRows($rows, $available, $requests);

        foreach ($evaluation as $lineId => $line) {
            // Shown to the operator: the full line quantity, not the part left after
            // a legacy special order.
            $evaluation[$lineId]['line_quantity'] = Decimal::normalize((string) $lines->firstWhere('id', $lineId)->quantity);
            $evaluation[$lineId]['legacy_procurement_quantity'] = $legacyCovered[$lineId];
            $evaluation[$lineId]['remaining_quantity'] = $this->atLeastZero(Decimal::subtract($line['shortage'], $line['covered_quantity']));
        }

        $shortageLines = array_filter($evaluation, fn (array $line) => $line['requires_sourcing']);
        $unresolved = array_filter($shortageLines, fn (array $line) => ! $line['resolved']);

        return [
            'lines' => $evaluation,
            'summary' => [
                'shortage_line_count' => count($shortageLines),
                'unresolved_line_count' => count($unresolved),
                // Every shortage covered by stock or full supplier confirmation:
                // the cart may become a Sales Order Draft (not a confirmed sale).
                'can_convert' => $shortageLines !== [] && $unresolved === [],
            ],
        ];
    }

    private function atLeastZero(string $value): string
    {
        return Decimal::compare($value, '0') < 0 ? '0.0000' : Decimal::normalize($value);
    }
}
