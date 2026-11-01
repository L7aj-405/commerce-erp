<?php

namespace App\Services;

use App\Enums\QuotationLineType;
use App\Enums\QuotationSupplierAvailabilityStatus as Status;
use App\Enums\WarehouseStatus;
use App\Models\InventoryBalance;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * Internal sourcing view of a Devis: for every line, how much internal stock
 * covers it, how much must come from a supplier, and whether supplier
 * availability is confirmed for that quantity.
 *
 * Everything is recomputed from the CURRENT line quantities, current stock and
 * the line's current article. A confirmation recorded for an older quantity or a
 * different article never silently satisfies a larger, newer requirement.
 *
 * This data is internal only — it must never reach a customer document.
 */
class QuotationSourcing
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array<int, array<string, mixed>> keyed by quotation line id, in line order
     */
    public function evaluate(Quotation $quotation, bool $lockRequests = false): array
    {
        $lines = $quotation->lines()->get();
        $variantIds = $lines
            ->filter(fn (QuotationLine $l) => $l->line_type === QuotationLineType::Catalog && $l->product_variant_id)
            ->pluck('product_variant_id')->all();
        $requests = QuotationSupplierAvailabilityRequest::query()
            ->where('organization_id', $quotation->organization_id)
            ->where('quotation_id', $quotation->getKey())
            ->where('status', '!=', Status::Cancelled->value)
            ->with(['supplier:id,name', 'requestedBy:id,name', 'resolvedBy:id,name'])
            ->orderBy('id')
            ->when($lockRequests, fn ($query) => $query->lockForUpdate())
            ->get()
            ->groupBy('quotation_line_id');

        $rows = $lines->map(fn (QuotationLine $line) => [
            'line_id' => (int) $line->getKey(),
            'line_type' => $line->line_type->value,
            'is_catalog' => $line->line_type === QuotationLineType::Catalog && $line->product_variant_id,
            'product_variant_id' => $line->product_variant_id,
            'non_stock_item_id' => $line->non_stock_item_id,
            'quantity' => (string) $line->quantity,
            'article' => trim(($line->product_name ?: $line->description).($line->variant_name ? ' — '.$line->variant_name : '')),
            'reference' => $line->reference ?: $line->sku,
        ])->all();

        return $this->evaluateRows($rows, $this->availableByVariant((int) $quotation->organization_id, $variantIds), $requests);
    }

    /**
     * The sourcing gate over normalized lines of ANY context (Devis lines, POS
     * cart lines). Internal stock is consumed in line order, so two lines of the
     * same variant never both count the same units. A line is resolved when its
     * shortage is covered by supplier confirmations of which at least one is a
     * full (or manual) confirmation — partial answers alone never satisfy it.
     *
     * @param  list<array{line_id: int, line_type: string, is_catalog: bool|int|null, product_variant_id: ?int, non_stock_item_id: ?int, quantity: string, article: string, reference: ?string}>  $rows
     * @param  array<int, string>  $available  free stock per variant
     * @param  Collection<int|string, Collection<int, QuotationSupplierAvailabilityRequest>>  $requestsByLine  non-cancelled requests keyed by line id
     * @return array<int, array<string, mixed>> keyed by line id, in line order
     */
    public function evaluateRows(array $rows, array $available, Collection $requestsByLine): array
    {
        $remaining = $available;
        $result = [];

        foreach ($rows as $row) {
            $quantity = Decimal::normalize($row['quantity']);
            $isCatalog = (bool) $row['is_catalog'];

            if ($isCatalog) {
                $variantId = (int) $row['product_variant_id'];
                $stock = $remaining[$variantId] ?? '0.0000';
                $fromStock = Decimal::compare($stock, $quantity) >= 0 ? $quantity : $this->atLeastZero($stock);
                $remaining[$variantId] = Decimal::subtract($stock, $fromStock);
                $shortage = Decimal::subtract($quantity, $fromStock);
            } else {
                // A non-stock article has no internal stock: the whole quantity is sourced.
                $shortage = $quantity;
            }

            $lineRequests = ($requestsByLine->get($row['line_id']) ?? collect())
                ->filter(fn (QuotationSupplierAvailabilityRequest $r) => $r->matchesArticle($row['product_variant_id'], $row['non_stock_item_id']))
                ->values();

            $supplierConfirmed = $this->sumConfirmed($lineRequests, [Status::Available, Status::Partial]);
            $manuallyConfirmed = $this->sumConfirmed($lineRequests, [Status::ConfirmedManually]);
            $covered = Decimal::add($supplierConfirmed, $manuallyConfirmed);

            // Partial supplier quantities are real and add up with other
            // confirmations, but partial answers alone never satisfy the gate:
            // at least one full supplier confirmation or a manual one is needed.
            $hasGateConfirmation = $lineRequests->contains(fn (QuotationSupplierAvailabilityRequest $r) => $r->status->satisfiesGate());
            $requiresSourcing = Decimal::compare($shortage, '0') > 0;
            $resolved = ! $requiresSourcing || ($hasGateConfirmation && Decimal::compare($covered, $shortage) >= 0);

            $result[$row['line_id']] = [
                'line_id' => (int) $row['line_id'],
                'line_type' => $row['line_type'],
                'article' => $row['article'],
                'reference' => $row['reference'],
                'product_variant_id' => $row['product_variant_id'],
                'non_stock_item_id' => $row['non_stock_item_id'],
                'requested_quantity' => $quantity,
                'internal_available' => $isCatalog ? $this->atLeastZero($available[(int) $row['product_variant_id']] ?? '0.0000') : null,
                'shortage' => $shortage,
                'covered_quantity' => $covered,
                'supplier_confirmed_quantity' => $supplierConfirmed,
                'manually_confirmed_quantity' => $manuallyConfirmed,
                'requires_sourcing' => $requiresSourcing,
                'resolved' => $resolved,
                'state' => $this->state($isCatalog, $requiresSourcing, $resolved, $lineRequests),
                'requests' => $lineRequests->map(fn (QuotationSupplierAvailabilityRequest $r) => [
                    'id' => (int) $r->getKey(),
                    'supplier_id' => $r->supplier_id,
                    'supplier_name' => $r->supplier?->name,
                    'status' => $r->status->value,
                    'requested_quantity' => $r->requested_quantity,
                    'confirmed_quantity' => $r->confirmed_quantity,
                    'note' => $r->note,
                    'requested_at' => $r->requested_at?->toIso8601String(),
                    'requested_by' => $r->requestedBy?->name,
                    'resolved_at' => $r->resolved_at?->toIso8601String(),
                    'resolved_by' => $r->resolvedBy?->name,
                ])->all(),
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $evaluation
     * @return array<int, array<string, mixed>>
     */
    public function unresolved(array $evaluation): array
    {
        return array_filter($evaluation, fn (array $line) => ! $line['resolved']);
    }

    /**
     * Record, for every unresolved line, a SEPARATE manual availability
     * confirmation by the actor for the quantity still uncovered. Supplier
     * responses (pending / partial / available / unavailable) are historical
     * facts and are never rewritten. The caller (IssueQuotationAction) owns the
     * transaction, the quotation row lock and the override permission check.
     *
     * @param  array<int, array<string, mixed>>  $unresolved
     * @return list<int> ids of the created manual confirmations
     */
    public function confirmManually(User $actor, Quotation $quotation, array $unresolved): array
    {
        $created = [];
        $lines = $quotation->lines()->whereKey(array_keys($unresolved))->get()->keyBy('id');

        foreach ($unresolved as $lineId => $evaluation) {
            /** @var QuotationLine $line */
            $line = $lines->get($lineId);
            if (! $line) {
                continue;
            }

            $remaining = Decimal::subtract($evaluation['shortage'], $evaluation['covered_quantity']);
            if (Decimal::compare($remaining, '0') <= 0) {
                // Partial supplier answers already add up to the shortage; only the
                // attestation is missing. A zero-quantity manual confirmation
                // records that attestation without inflating any quantity.
                $remaining = '0.0000';
            }

            $confirmation = new QuotationSupplierAvailabilityRequest;
            $confirmation->organization_id = $quotation->organization_id;
            $confirmation->store_id = $quotation->store_id;
            $confirmation->quotation_id = $quotation->getKey();
            $confirmation->quotation_line_id = $line->getKey();
            $confirmation->supplier_id = $this->soleKnownSupplier($line);
            $confirmation->product_variant_id = $line->product_variant_id;
            $confirmation->non_stock_item_id = $line->non_stock_item_id;
            $confirmation->requested_quantity = $remaining;
            $confirmation->confirmed_quantity = $remaining;
            $confirmation->status = Status::ConfirmedManually;
            $confirmation->requested_by_user_id = $actor->getKey();
            $confirmation->requested_at = now();
            // resolved_* = who attested the availability, and when.
            $confirmation->resolved_by_user_id = $actor->getKey();
            $confirmation->resolved_at = now();
            $confirmation->save();
            $created[] = (int) $confirmation->getKey();

            $this->audit->record('quotation_supplier_availability.manually_confirmed', $actor, $quotation->organization, $quotation->store, $confirmation, newValues: [
                'quotation_id' => $quotation->getKey(),
                'quotation_line_id' => $line->getKey(),
                'supplier_id' => $confirmation->supplier_id,
                'status' => Status::ConfirmedManually->value,
                'shortage' => $evaluation['shortage'],
                'supplier_confirmed_quantity' => $evaluation['supplier_confirmed_quantity'],
                'manually_confirmed_quantity' => $remaining,
                'confirmed_at' => $confirmation->resolved_at->toIso8601String(),
            ]);
        }

        return $created;
    }

    /**
     * The supplier the line is being sourced from, when it is unambiguous: every
     * live (non-cancelled, non-unavailable) request names the same supplier.
     * Otherwise null — a supplier is never guessed.
     */
    private function soleKnownSupplier(QuotationLine $line): ?int
    {
        $suppliers = QuotationSupplierAvailabilityRequest::query()
            ->where('organization_id', $line->organization_id)
            ->where('quotation_line_id', $line->getKey())
            ->whereIn('status', [Status::Pending->value, Status::Partial->value, Status::Available->value])
            ->whereNotNull('supplier_id')
            ->get()
            ->filter(fn (QuotationSupplierAvailabilityRequest $r) => $r->matchesLineArticle($line))
            ->pluck('supplier_id')
            ->unique();

        return $suppliers->count() === 1 ? (int) $suppliers->first() : null;
    }

    /**
     * @param  Collection<int, QuotationSupplierAvailabilityRequest>  $requests
     * @param  list<Status>  $statuses
     */
    private function sumConfirmed(Collection $requests, array $statuses): string
    {
        return $requests
            ->filter(fn (QuotationSupplierAvailabilityRequest $r) => in_array($r->status, $statuses, true))
            ->reduce(fn (string $sum, QuotationSupplierAvailabilityRequest $r) => Decimal::add($sum, (string) ($r->confirmed_quantity ?? '0')), '0.0000');
    }

    /**
     * Total available (on hand − reserved) per variant across the
     * organisation's active warehouses — same basis as the Devis article search
     * and the POS company-wide allocator.
     *
     * @param  array<int, int|string>  $variantIds
     * @return array<int, string>
     */
    public function availableByVariant(int $organizationId, array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        if ($variantIds === []) {
            return [];
        }

        return InventoryBalance::query()
            ->where('organization_id', $organizationId)
            ->whereIn('product_variant_id', $variantIds)
            ->whereHas('warehouse', fn ($q) => $q->where('status', WarehouseStatus::Active->value))
            ->get()
            ->groupBy('product_variant_id')
            ->map(fn ($rows) => $rows->reduce(fn (string $c, InventoryBalance $b) => Decimal::add($c, $b->available), '0.0000'))
            ->all();
    }

    /** @param Collection<int, QuotationSupplierAvailabilityRequest> $requests */
    private function state(bool $isCatalog, bool $requiresSourcing, bool $resolved, Collection $requests): string
    {
        if (! $requiresSourcing) {
            return $isCatalog ? 'in_stock' : 'covered';
        }
        if ($resolved) {
            return $requests->contains(fn ($r) => $r->status === Status::ConfirmedManually) ? 'confirmed_manually' : 'available';
        }
        if ($requests->contains(fn ($r) => $r->status->satisfiesGate())) {
            // Confirmed for an older, smaller requirement — no longer enough.
            return 'insufficient';
        }
        foreach ([Status::Pending, Status::Partial, Status::Unavailable] as $status) {
            if ($requests->contains(fn ($r) => $r->status === $status)) {
                return $status->value;
            }
        }

        return 'to_source';
    }

    private function atLeastZero(string $value): string
    {
        return Decimal::compare($value, '0') < 0 ? '0.0000' : Decimal::normalize($value);
    }
}
