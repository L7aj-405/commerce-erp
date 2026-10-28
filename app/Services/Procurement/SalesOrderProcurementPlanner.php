<?php

namespace App\Services\Procurement;

use App\Enums\InventoryReservationStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\QuotationLineType;
use App\Enums\QuotationSupplierAvailabilityStatus;
use App\Enums\SalesOrderLineType;
use App\Enums\SalesOrderStatus;
use App\Enums\SupplierAvailabilityStatus;
use App\Enums\SupplierProcurementStatus;
use App\Models\InventoryReservation;
use App\Models\PurchaseOrderLine;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesOrderProcurement;
use App\Services\PosStockAllocator;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * Procurement readiness of a Sales Order, kept apart from its commercial
 * status. For every catalogue line: how much internal stock covers it, how much
 * comes from a supplier (the procurement requirements, `sales_order_procurements`),
 * how much of that is on an active purchase order, and what is still unresolved.
 *
 * It also finds the requirements that can be DERIVED from the accepted quotation
 * the order was converted from: a current shortage on a line whose quotation
 * line carries a supplier availability confirmation (available, partial or
 * manual — never pending, unavailable or cancelled) of at least that shortage
 * from one active supplier. Only the converted quotation's own answers count,
 * never those of a superseded version. The quantity is always the CURRENT Sales
 * Order shortage, not the quantity once confirmed.
 *
 * Line states: covered_by_stock, procurement_required, procurement_ordered,
 * procurement_partially_received, procurement_received, unresolved,
 * not_applicable (custom lines / cancelled orders).
 *
 * It is the one coverage calculation of a Sales Order: the order page, the MCP
 * read model and confirmation preview, the attention engine and
 * ConfirmSalesOrderAction (confirmationBlockers) all read it. Only physical
 * coverage makes a line confirmation-ready: stock reserved to the order
 * (received supplier goods included) or free internal stock — never a supplier
 * confirmation, a purchase order or an expected delivery for a requirement
 * derived from the quotation.
 */
final class SalesOrderProcurementPlanner
{
    public const COVERED = 'covered_by_stock';
    public const REQUIRED = 'procurement_required';
    public const ORDERED = 'procurement_ordered';
    public const PARTIALLY_RECEIVED = 'procurement_partially_received';
    public const RECEIVED = 'procurement_received';
    public const UNRESOLVED = 'unresolved';
    public const NOT_APPLICABLE = 'not_applicable';

    private const CONFIRMING = [
        QuotationSupplierAvailabilityStatus::Available,
        QuotationSupplierAvailabilityStatus::Partial,
        QuotationSupplierAvailabilityStatus::ConfirmedManually,
    ];

    public function __construct(private readonly PosStockAllocator $stock) {}

    /**
     * @return array{summary: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    public function plan(SalesOrder $order): array
    {
        $context = $this->context($order);
        $lines = [];
        foreach ($context['lines'] as $line) {
            $plan = $this->linePlan($order, $line, $context);
            unset($plan['evidence']);
            $lines[] = $plan;
        }

        return ['summary' => $this->summary($order, $lines, $context), 'lines' => $lines];
    }

    /**
     * Why a draft order cannot be confirmed yet because of quotation-derived
     * procurement: supplier goods not (all) physically received.
     *
     * @return list<array{path: string, code: string, message: string, details: array<string, mixed>}>
     */
    public function confirmationBlockers(SalesOrder $order): array
    {
        if ($order->status !== SalesOrderStatus::Draft) {
            return [];
        }

        $blockers = [];
        foreach ($this->plan($order)['lines'] as $line) {
            if (! $line['awaiting_receipt']) {
                continue;
            }
            $purchaseOrder = collect($line['purchase_orders'])->first(fn (array $po) => Decimal::compare($po['remaining_to_receive'], '0') > 0)
                ?? $line['purchase_orders'][0] ?? null;
            $blockers[] = [
                'path' => "lines.{$line['line_id']}",
                'code' => 'procurement_not_received',
                'message' => "Approvisionnement non réceptionné pour {$line['article']} : {$line['procurement_received']} reçu(s) sur {$line['procurement_quantity']}. La commande pourra être confirmée après réception de la marchandise fournisseur.",
                'details' => [
                    'sales_order_id' => (int) $order->getKey(),
                    'sales_order_line_id' => $line['line_id'],
                    'required' => $line['procurement_quantity'],
                    'ordered' => $line['procurement_ordered'],
                    'received' => $line['procurement_received'],
                    'remaining' => $line['procurement_remaining'],
                    'purchase_order' => $purchaseOrder['number'] ?? null,
                ],
            ];
        }

        return $blockers;
    }

    /**
     * Requirements that can be created from the converted quotation right now.
     * The caller holds the Sales Order row lock.
     *
     * @return list<array{line: SalesOrderLine, quantity: string, quotation: Quotation, quotation_line: QuotationLine, request: QuotationSupplierAvailabilityRequest}>
     */
    public function derivable(SalesOrder $order): array
    {
        if (! in_array($order->status, [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed], true)) {
            return [];
        }
        $context = $this->context($order);
        if ($context['quotation'] === null) {
            return [];
        }

        $result = [];
        foreach ($context['lines'] as $line) {
            $plan = $this->linePlan($order, $line, $context);
            if ($plan['derivable']) {
                $result[] = [
                    'line' => $line,
                    'quantity' => $plan['uncovered_quantity'],
                    'quotation' => $context['quotation'],
                    'quotation_line' => $context['candidates'][$line->getKey()]['quotation_line'],
                    'request' => $plan['evidence'],
                ];
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function context(SalesOrder $order): array
    {
        $lines = SalesOrderLine::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())
            ->with(['productVariant', 'allocations'])->orderBy('position')->orderBy('id')->get();

        $procurements = SalesOrderProcurement::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())
            ->with(['supplier:id,name', 'sourceQuotation:id,quotation_number,revision_number'])->orderBy('id')->get();

        $poLines = PurchaseOrderLine::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())
            ->with('purchaseOrder:id,purchase_order_number,status,expected_delivery_date,supplier_id,ordered_at')
            ->orderBy('id')->get()
            ->filter(fn (PurchaseOrderLine $line) => $line->purchaseOrder?->status !== PurchaseOrderStatus::Cancelled)
            ->groupBy('sales_order_procurement_id');

        // Goods received while the order is a draft, held by their own reservation.
        $receiptHeld = $procurements->isEmpty() ? collect() : InventoryReservation::query()->where('organization_id', $order->organization_id)
            ->where('reference_type', SalesOrderProcurement::class)->whereIn('reference_id', $procurements->modelKeys())
            ->where('status', InventoryReservationStatus::Active->value)->get(['reference_id', 'quantity'])
            ->groupBy('reference_id')->map(fn ($rows) => $rows->reduce(fn (string $sum, $r) => Decimal::add($sum, (string) $r->quantity), '0.0000'));

        $sourceRequestIds = $procurements->pluck('source_supplier_request_id')->filter()->unique()->values()->all();
        $sourceRequests = $sourceRequestIds === [] ? collect() : QuotationSupplierAvailabilityRequest::query()
            ->where('organization_id', $order->organization_id)->whereKey($sourceRequestIds)->get(['id', 'status'])->keyBy('id');

        $quotation = Quotation::query()->where('organization_id', $order->organization_id)
            ->where('converted_sales_order_id', $order->getKey())->first(['id', 'organization_id', 'quotation_number', 'revision_number']);

        return [
            'lines' => $lines,
            'procurements' => $procurements->groupBy('sales_order_line_id'),
            'po_lines' => $poLines,
            'source_requests' => $sourceRequests,
            'receipt_held' => $receiptHeld,
            'quotation' => $quotation,
            'candidates' => $quotation ? $this->candidates($order, $quotation, $lines) : [],
        ];
    }

    /**
     * Per Sales Order line: the quotation line it came from (same article, in
     * line order) and that line's supplier confirmations grouped by supplier.
     *
     * @param  Collection<int, SalesOrderLine>  $lines
     * @return array<int, array<string, mixed>> keyed by Sales Order line id
     */
    private function candidates(SalesOrder $order, Quotation $quotation, Collection $lines): array
    {
        $quotationLines = QuotationLine::query()->where('organization_id', $order->organization_id)->where('quotation_id', $quotation->getKey())
            ->where('line_type', QuotationLineType::Catalog->value)->orderBy('position')->orderBy('id')->get();
        $requests = QuotationSupplierAvailabilityRequest::query()->where('organization_id', $order->organization_id)
            ->where('quotation_id', $quotation->getKey())
            ->whereIn('status', array_map(fn ($status) => $status->value, self::CONFIRMING))
            ->whereNotNull('supplier_id')
            ->with('supplier:id,name,active')->orderBy('id')->get()->groupBy('quotation_line_id');

        $used = [];
        $result = [];
        foreach ($lines as $line) {
            if ($line->line_type !== SalesOrderLineType::Catalog || $line->product_variant_id === null) {
                continue;
            }
            $match = $quotationLines->first(fn (QuotationLine $q) => ! isset($used[$q->getKey()]) && (int) $q->product_variant_id === (int) $line->product_variant_id);
            if (! $match) {
                continue;
            }
            $used[$match->getKey()] = true;

            $bySupplier = ($requests->get($match->getKey()) ?? collect())
                ->filter(fn (QuotationSupplierAvailabilityRequest $r) => $r->matchesLineArticle($match) && $r->supplier?->active)
                ->groupBy('supplier_id')
                ->map(function (Collection $rows) {
                    $available = $rows->first(fn ($r) => $r->status !== QuotationSupplierAvailabilityStatus::ConfirmedManually);

                    return [
                        'confirmed' => $rows->reduce(fn (string $sum, $r) => Decimal::add($sum, (string) ($r->confirmed_quantity ?? '0')), '0.0000'),
                        'from_supplier' => $available !== null,
                        // The supplier's own answer is the evidence when there is one.
                        'request' => $available ?? $rows->first(),
                    ];
                })
                // The supplier's own confirmation first, then the larger quantity, then the earliest answer.
                ->sort(fn (array $a, array $b) => ($b['from_supplier'] <=> $a['from_supplier'])
                    ?: Decimal::compare($b['confirmed'], $a['confirmed'])
                    ?: $a['request']->getKey() <=> $b['request']->getKey())
                ->values();

            $result[$line->getKey()] = ['quotation_line' => $match, 'suppliers' => $bySupplier];
        }

        return $result;
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function linePlan(SalesOrder $order, SalesOrderLine $line, array $context): array
    {
        $quantity = Decimal::normalize((string) $line->quantity);
        $base = [
            'line_id' => (int) $line->getKey(),
            'product_variant_id' => $line->product_variant_id,
            'article' => trim($line->product_name.($line->variant_name ? ' — '.$line->variant_name : '')),
            'requested_quantity' => $quantity,
        ];
        $isCatalog = $line->line_type === SalesOrderLineType::Catalog && $line->productVariant !== null;
        if (! $isCatalog || $order->status === SalesOrderStatus::Cancelled) {
            return [...$base, 'state' => self::NOT_APPLICABLE, ...$this->emptyFigures(), 'derivable' => false, 'awaiting_receipt' => false, 'evidence' => null];
        }

        // Internal stock covering the line: reserved allocations once confirmed,
        // the free stock currently available to it while still a draft (goods
        // reserved for this order are not free and are counted apart).
        $confirmed = $order->status === SalesOrderStatus::Confirmed;
        $allocated = $line->allocations->reduce(fn (string $sum, $allocation) => Decimal::add($sum, (string) $allocation->quantity), '0.0000');
        $free = $confirmed ? null : Decimal::normalize($this->stock->availableForLine($order, $line->productVariant, $line));
        $stock = $confirmed ? $allocated : $free;
        $stock = Decimal::compare($stock, $quantity) > 0 ? $quantity : Decimal::normalize($stock);

        $rows = $context['procurements']->get($line->getKey()) ?? collect();
        $live = $rows
            ->reject(fn (SalesOrderProcurement $p) => in_array($p->status, [SupplierProcurementStatus::Cancelled, SupplierProcurementStatus::Unavailable], true))
            ->values();
        $covering = $live->filter(fn (SalesOrderProcurement $p) => $p->status->coversSalesLine());
        $pendingSupplier = $live->contains(fn (SalesOrderProcurement $p) => ! $p->status->coversSalesLine());
        $unavailable = $rows->contains(fn (SalesOrderProcurement $p) => $p->status === SupplierProcurementStatus::Unavailable);

        $procured = '0.0000';
        $ordered = '0.0000';
        $received = '0.0000';
        $held = '0.0000';
        $commitment = '0.0000';
        $awaitingReceipt = false;
        $purchaseOrders = [];
        foreach ($covering as $procurement) {
            $procured = Decimal::add($procured, (string) $procurement->quantity);
            $onPurchaseOrders = ($context['po_lines']->get($procurement->getKey()) ?? collect());
            $poQuantity = $onPurchaseOrders->reduce(fn (string $sum, PurchaseOrderLine $l) => Decimal::add($sum, (string) $l->quantity), '0.0000');
            // Ordered without a purchase order (V1 special order): the whole quantity.
            $isOrdered = in_array($procurement->status, [SupplierProcurementStatus::Ordered, SupplierProcurementStatus::Received, SupplierProcurementStatus::Completed], true)
                || ($procurement->status === SupplierProcurementStatus::PartiallyReceived && $onPurchaseOrders->isEmpty());
            $ordered = Decimal::add($ordered, $isOrdered ? (string) $procurement->quantity : $this->min($poQuantity, (string) $procurement->quantity));
            $received = Decimal::add($received, (string) $procurement->received_quantity);
            $held = Decimal::add($held, $context['receipt_held']->get($procurement->getKey(), '0.0000'));
            $notReceived = $this->atLeastZero(Decimal::subtract((string) $procurement->quantity, (string) $procurement->received_quantity));
            if ($procurement->source_quotation_id !== null) {
                // Derived from the quotation: only the goods themselves cover it.
                $awaitingReceipt = $awaitingReceipt || Decimal::compare($notReceived, '0') > 0;
            } else {
                // V1 special order: the confirmed supplier commitment covers it (unchanged rule).
                $commitment = Decimal::add($commitment, $notReceived);
            }
            foreach ($onPurchaseOrders as $poLine) {
                $purchaseOrders[] = [
                    'id' => (int) $poLine->purchase_order_id,
                    'line_id' => (int) $poLine->getKey(),
                    'number' => $poLine->purchaseOrder?->purchase_order_number,
                    'status' => $poLine->purchaseOrder?->status?->value,
                    'quantity' => Decimal::normalize((string) $poLine->quantity),
                    'received_quantity' => Decimal::normalize((string) $poLine->received_quantity),
                    'remaining_to_receive' => $this->atLeastZero(Decimal::subtract((string) $poLine->quantity, (string) $poLine->received_quantity)),
                    'expected_delivery_date' => $poLine->purchaseOrder?->expected_delivery_date?->toDateString(),
                ];
            }
        }

        $shortage = Decimal::subtract($quantity, $stock);
        $uncovered = Decimal::subtract($shortage, $procured);
        if (Decimal::compare($uncovered, '0') < 0) {
            $uncovered = '0.0000';
        }
        $remaining = Decimal::subtract($procured, $ordered);

        // A still-uncovered shortage on a line nobody has raised a requirement
        // for yet (cancelled / unavailable ones included) may be derived from
        // the quotation; a supplier that later refused is never revived.
        $candidate = null;
        if (Decimal::compare($uncovered, '0') > 0 && $rows->isEmpty()) {
            $candidate = collect($context['candidates'][$line->getKey()]['suppliers'] ?? [])
                ->first(fn (array $supplier) => Decimal::compare($supplier['confirmed'], $uncovered) >= 0);
        }

        $first = $covering->first() ?? $live->first();
        $supplier = $first?->supplier ?? $candidate['request']->supplier ?? null;

        $state = match (true) {
            $candidate !== null => self::REQUIRED,
            Decimal::compare($uncovered, '0') > 0 || $pendingSupplier => self::UNRESOLVED,
            $covering->isEmpty() => self::COVERED,
            Decimal::compare($remaining, '0') > 0 => self::REQUIRED,
            Decimal::compare($received, $procured) >= 0 => self::RECEIVED,
            Decimal::compare($received, '0') > 0 => self::PARTIALLY_RECEIVED,
            default => self::ORDERED,
        };

        // Physical coverage: reserved to this order (allocations once confirmed;
        // received supplier goods held while a draft) + free stock it may take +
        // the V1 supplier commitment. Never a quotation-derived requirement not received.
        $covered = $confirmed
            ? Decimal::add($allocated, $commitment)
            : Decimal::add(Decimal::add($stock, $held), $commitment);
        $covered = Decimal::compare($covered, $quantity) > 0 ? $quantity : $covered;
        $awaitingReceipt = $candidate !== null || $awaitingReceipt;

        return [
            ...$base,
            'state' => $state,
            'internal_stock_covered_quantity' => $stock,
            'procurement_quantity' => $candidate !== null ? $uncovered : $procured,
            'ordered_quantity' => $ordered,
            'remaining_to_order' => $candidate !== null ? $uncovered : $this->atLeastZero($remaining),
            'received_quantity' => $received,
            'uncovered_quantity' => $uncovered,
            // Canonical coverage figures.
            'internal_available_unreserved' => $free,
            'already_reserved_for_this_order' => $confirmed ? $allocated : $held,
            'procurement_ordered' => $ordered,
            'procurement_received' => $received,
            'procurement_remaining' => $candidate !== null ? $uncovered : $this->atLeastZero(Decimal::subtract($procured, $received)),
            'total_covered_quantity' => $covered,
            'confirmation_ready' => $confirmed || (! $awaitingReceipt && ! $pendingSupplier && Decimal::compare($covered, $quantity) >= 0),
            'unresolved_reason' => $state !== self::UNRESOLVED ? null
                : ($pendingSupplier ? 'supplier_not_confirmed' : ($unavailable ? 'supplier_unavailable' : 'no_supplier_confirmation')),
            'requirement_ids' => $covering->map(fn ($p) => (int) $p->getKey())->values()->all(),
            'requirements' => $live->map(fn (SalesOrderProcurement $p) => [
                'id' => (int) $p->getKey(),
                'number' => $p->procurement_number,
                'status' => $p->status->value,
                'quantity' => Decimal::normalize((string) $p->quantity),
                'supplier_availability_status' => $p->supplier_availability_status->value,
            ])->values()->all(),
            'supplier' => $supplier ? ['id' => (int) $supplier->id, 'name' => $supplier->name] : null,
            'availability' => $this->availability($first, $candidate, $context),
            'purchase_orders' => $purchaseOrders,
            'source_quotation' => $this->sourceQuotation($first, $candidate, $context),
            'derivable' => $candidate !== null,
            // Quotation-derived quantity the order still waits for physically (only receipt makes it confirmable).
            'awaiting_receipt' => $awaitingReceipt,
            'evidence' => $candidate['request'] ?? null,
        ];
    }

    /** @param array<string, mixed>|null $candidate @param array<string, mixed> $context @return array{status: string, source: string}|null */
    private function availability(?SalesOrderProcurement $procurement, ?array $candidate, array $context): ?array
    {
        if ($candidate !== null) {
            return ['status' => SupplierAvailabilityStatus::ConfirmedAvailable->value, 'source' => $candidate['from_supplier'] ? 'quotation' : 'manual'];
        }
        if ($procurement === null) {
            return null;
        }
        $request = $procurement->source_supplier_request_id ? $context['source_requests']->get($procurement->source_supplier_request_id) : null;

        return [
            'status' => $procurement->supplier_availability_status->value,
            'source' => $request === null ? 'procurement' : ($request->status === QuotationSupplierAvailabilityStatus::ConfirmedManually ? 'manual' : 'quotation'),
        ];
    }

    /** @param array<string, mixed>|null $candidate @param array<string, mixed> $context @return array{id: int, number: ?string}|null */
    private function sourceQuotation(?SalesOrderProcurement $procurement, ?array $candidate, array $context): ?array
    {
        $quotation = $procurement?->sourceQuotation ?? ($candidate !== null ? $context['quotation'] : null);

        return $quotation ? ['id' => (int) $quotation->id, 'number' => $quotation->quotation_number] : null;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function summary(SalesOrder $order, array $lines, array $context): array
    {
        $count = fn (array $states) => count(array_filter($lines, fn (array $line) => in_array($line['state'], $states, true)));
        $requiredLines = $count([self::REQUIRED, self::ORDERED, self::PARTIALLY_RECEIVED, self::RECEIVED, self::UNRESOLVED]);
        $toOrder = $count([self::REQUIRED]);
        $unresolved = $count([self::UNRESOLVED]);
        $orderedLines = $count([self::ORDERED, self::PARTIALLY_RECEIVED, self::RECEIVED]);

        // Open requirements grouped by supplier: what one purchase order may cover
        // (one still to be derived from the quotation is created when ordering).
        $suppliers = [];
        foreach ($lines as $line) {
            if ($line['state'] !== self::REQUIRED || $line['supplier'] === null) {
                continue;
            }
            $key = $line['supplier']['id'];
            $suppliers[$key] ??= ['supplier' => $line['supplier'], 'requirement_ids' => [], 'line_count' => 0];
            $suppliers[$key]['requirement_ids'] = [...$suppliers[$key]['requirement_ids'], ...$line['requirement_ids']];
            $suppliers[$key]['line_count']++;
        }

        $orderable = in_array($order->status, [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed], true);
        $pendingDerivation = count(array_filter($lines, fn (array $line) => $line['derivable']));

        return [
            'state' => match (true) {
                $order->status === SalesOrderStatus::Cancelled => self::NOT_APPLICABLE,
                $requiredLines === 0 => 'covered',
                $unresolved > 0 => 'unresolved',
                $toOrder > 0 => 'awaiting_procurement',
                $count([self::ORDERED]) > 0 => 'ordered',
                $count([self::PARTIALLY_RECEIVED]) > 0 => 'partially_received',
                default => 'received',
            },
            'required' => $requiredLines > 0,
            // A draft order is orderable: the purchase order comes before confirmation.
            'ready_for_ordering' => $orderable && $suppliers !== [],
            'ordered' => $requiredLines > 0 && $orderedLines === $requiredLines,
            'unresolved_count' => $unresolved,
            'required_line_count' => $requiredLines,
            'to_order_line_count' => $toOrder,
            'ordered_line_count' => $orderedLines,
            // Requirements still to be created from the quotation (when the purchase order is created).
            'pending_derivation_count' => $pendingDerivation,
            // Confirmation waits for the supplier goods to be received (never for the purchase order alone).
            'confirmation_waits_for_receipt' => $order->status === SalesOrderStatus::Draft
                && count(array_filter($lines, fn (array $line) => $line['awaiting_receipt'])) > 0,
            'receipt_pending_line_count' => $count([self::ORDERED, self::PARTIALLY_RECEIVED]),
            // Every stock-tracked line physically covered (a draft that may be confirmed now).
            'confirmation_ready' => $order->status === SalesOrderStatus::Draft
                && count(array_filter($lines, fn (array $line) => $line['state'] !== self::NOT_APPLICABLE && ! $line['confirmation_ready'])) === 0,
            'suppliers' => array_values($suppliers),
            'source_quotation' => $context['quotation'] ? ['id' => (int) $context['quotation']->id, 'number' => $context['quotation']->quotation_number] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyFigures(): array
    {
        return [
            'internal_stock_covered_quantity' => null, 'procurement_quantity' => '0.0000', 'ordered_quantity' => '0.0000',
            'remaining_to_order' => '0.0000', 'received_quantity' => '0.0000', 'uncovered_quantity' => '0.0000', 'unresolved_reason' => null,
            'internal_available_unreserved' => null, 'already_reserved_for_this_order' => '0.0000', 'procurement_ordered' => '0.0000',
            'procurement_received' => '0.0000', 'procurement_remaining' => '0.0000', 'total_covered_quantity' => null, 'confirmation_ready' => true,
            'requirement_ids' => [], 'requirements' => [], 'supplier' => null, 'availability' => null, 'purchase_orders' => [], 'source_quotation' => null,
        ];
    }

    private function min(string $a, string $b): string
    {
        return Decimal::compare($a, $b) <= 0 ? Decimal::normalize($a) : Decimal::normalize($b);
    }

    private function atLeastZero(string $value): string
    {
        return Decimal::compare($value, '0') < 0 ? '0.0000' : Decimal::normalize($value);
    }
}
