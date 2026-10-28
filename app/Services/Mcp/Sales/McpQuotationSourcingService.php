<?php

namespace App\Services\Mcp\Sales;

use App\Actions\Quotations\CancelQuotationSupplierRequestAction;
use App\Actions\Quotations\CreateQuotationSupplierRequestAction;
use App\Actions\Quotations\RecordQuotationSupplierAvailabilityAction;
use App\Enums\QuotationSupplierAvailabilityStatus as Status;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Services\Mcp\McpExecutionContext;
use App\Services\QuotationSourcing;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Quotation supplier sourcing over MCP: a thin adapter. Coverage numbers come
 * only from QuotationSourcing (the same evaluation the web editor and the
 * IssueQuotationAction gate use), and every mutation is the canonical action
 * (create / record response / cancel), which re-checks permission, draft
 * state and store membership and writes the audit trail.
 *
 * A supplier availability request is internal tracking only: nothing is sent
 * to the supplier and it is not a purchase order. MCP can never create a
 * manual confirmation (`confirmed_manually`) — that stays a web/admin action.
 */
final class McpQuotationSourcingService
{
    /** Idempotency target type (mcp_idempotency_records.target_type is varchar(32)). */
    public const TARGET_TYPE = 'supplier_request';

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly QuotationReadService $quotations,
        private readonly QuotationSourcing $sourcing,
        private readonly CreateQuotationSupplierRequestAction $createAction,
        private readonly RecordQuotationSupplierAvailabilityAction $recordAction,
        private readonly CancelQuotationSupplierRequestAction $cancelAction,
    ) {}

    /** Full sourcing view of one quotation (get_quotation_sourcing). @return array<string, mixed> */
    public function sourcing(int $quotationId): array
    {
        return $this->summary($this->quotations->find($this->context->organization(), $quotationId), detailed: true);
    }

    /**
     * Sourcing of a quotation. Readiness is only evaluated for a DRAFT: an
     * issued quotation is frozen and its sourcing was settled at issue time.
     *
     * @return array<string, mixed>
     */
    public function summary(Quotation $quotation, bool $detailed): array
    {
        if (! $quotation->status->isEditable()) {
            return [
                'quotation_id' => $quotation->getKey(),
                'quotation_status' => $quotation->status->value,
                'evaluated' => false,
                'message' => 'Sourcing is only evaluated on draft quotations; this quotation is no longer a draft (its sourcing was settled when it was issued).',
                ...($detailed ? ['requests' => $this->history($quotation)] : []),
            ];
        }

        $lines = array_values(array_map(fn (array $line) => $this->line($line, $detailed), $this->sourcing->evaluate($quotation)));
        $unresolved = array_values(array_filter($lines, fn (array $line) => ! $line['sourcing_ready']));

        return [
            'quotation_id' => $quotation->getKey(),
            'quotation_status' => $quotation->status->value,
            'evaluated' => true,
            'sourcing_required' => collect($lines)->contains('sourcing_required', true),
            'sourcing_ready' => $unresolved === [],
            'unresolved_count' => count($unresolved),
            'unresolved_lines' => array_column($unresolved, 'quotation_line_id'),
            'lines' => $lines,
            'note' => 'A line needs sourcing when internal stock does not cover it (catalog) or always (non-stock). It is ready once "available" supplier answers plus internal manual confirmations cover its current shortage. Partial answers alone never make a line ready. issue_quotation is rejected while sourcing_ready is false.',
        ];
    }

    /**
     * Must run inside the caller's transaction (idempotency wrapper).
     *
     * @param array{quotation_id: int, quotation_line_id: int, supplier_id: int, requested_quantity?: ?string, note?: ?string} $payload
     * @return array<string, mixed>
     */
    public function createRequest(array $payload): array
    {
        [$quotation, $line] = $this->quotationAndLine($payload['quotation_id'], $payload['quotation_line_id']);

        // The canonical action defaults the quantity to the line's current shortage
        // (catalog) or full quantity (non-stock) and refuses meaningless duplicates.
        $request = $this->createAction->execute($this->context->user(), $quotation, $line, array_filter([
            'supplier_id' => $payload['supplier_id'],
            'requested_quantity' => $payload['requested_quantity'] ?? null,
            'note' => $payload['note'] ?? null,
        ], fn ($value) => $value !== null));

        return $this->outcome((int) $quotation->getKey(), $request);
    }

    /** @return array<string, mixed> */
    public function recordResponse(int $quotationId, int $requestId, string $confirmedQuantity, ?string $note, ?string $expectedStatus): array
    {
        [$quotation, $request] = $this->quotationAndRequest($quotationId, $requestId);
        $this->guardExpected($request, $expectedStatus);
        if ($request->status === Status::ConfirmedManually) {
            throw ValidationException::withMessages(['status' => 'A manual confirmation is internal and read-only; it has no supplier response to record.']);
        }

        $confirmed = Decimal::nonNegative($confirmedQuantity, 'confirmed_quantity');
        // The caller only states the supplier's quantity; the ERP derives the
        // status (available / partial / unavailable) from it.
        $updated = $this->recordAction->execute($this->context->user(), $quotation, $request, array_filter([
            'outcome' => Decimal::compare($confirmed, '0') > 0 ? 'available' : 'unavailable',
            'confirmed_quantity' => $confirmed,
            'note' => $note,
        ], fn ($value) => $value !== null));

        return $this->outcome($quotationId, $updated);
    }

    /** @return array<string, mixed> */
    public function cancelRequest(int $quotationId, int $requestId, ?string $expectedStatus): array
    {
        [$quotation, $request] = $this->quotationAndRequest($quotationId, $requestId);
        $this->guardExpected($request, $expectedStatus);
        if ($request->status === Status::ConfirmedManually) {
            throw ValidationException::withMessages(['status' => 'A manual confirmation cannot be cancelled over MCP.']);
        }

        return $this->outcome($quotationId, $this->cancelAction->execute($this->context->user(), $quotation, $request));
    }

    /** @return array<string, mixed> */
    public function presentRequest(QuotationSupplierAvailabilityRequest $request): array
    {
        $request->loadMissing(['supplier:id,name', 'requestedBy:id,name', 'resolvedBy:id,name']);

        return [
            'id' => (int) $request->getKey(),
            'quotation_id' => (int) $request->quotation_id,
            'quotation_line_id' => (int) $request->quotation_line_id,
            'supplier' => $request->supplier ? ['id' => (int) $request->supplier->getKey(), 'name' => $request->supplier->name] : null,
            'requested_quantity' => (string) $request->requested_quantity,
            'confirmed_quantity' => $request->confirmed_quantity === null ? null : (string) $request->confirmed_quantity,
            'status' => $request->status->value,
            'is_manual_confirmation' => $request->status === Status::ConfirmedManually,
            ...($this->canSeeNotes() ? ['note' => $request->note] : []),
            'requested_at' => $request->requested_at?->toIso8601String(),
            'requested_by' => $request->requestedBy?->name,
            'resolved_at' => $request->resolved_at?->toIso8601String(),
            'resolved_by' => $request->resolvedBy?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function outcome(int $quotationId, QuotationSupplierAvailabilityRequest $request): array
    {
        $quotation = $this->quotations->find($this->context->organization(), $quotationId);
        $summary = $this->summary($quotation, detailed: true);
        $line = collect($summary['lines'] ?? [])->firstWhere('quotation_line_id', (int) $request->quotation_line_id);

        return [
            'target' => ['type' => self::TARGET_TYPE, 'id' => (int) $request->getKey()],
            'request' => $this->presentRequest($request->fresh()),
            'line_sourcing' => $line,
            'quotation_sourcing' => array_diff_key($summary, ['lines' => true]),
        ];
    }

    /**
     * Compact (get_quotation) or detailed (get_quotation_sourcing) line view.
     *
     * @param array<string, mixed> $line evaluation row from QuotationSourcing
     * @return array<string, mixed>
     */
    private function line(array $line, bool $detailed): array
    {
        $remaining = Decimal::subtract($line['shortage'], $line['covered_quantity']);
        $remaining = Decimal::compare($remaining, '0') < 0 ? '0.0000' : $remaining;

        $compact = [
            'quotation_line_id' => $line['line_id'],
            'sourcing_required' => $line['requires_sourcing'],
            'sourcing_ready' => $line['resolved'],
            'internal_available_quantity' => $line['internal_available'],
            'shortage_quantity' => $line['shortage'],
        ];
        if (! $detailed) return $compact;

        return [
            ...$compact,
            'article_type' => $line['line_type'],
            'article' => [
                'name' => $line['article'],
                'reference' => $line['reference'],
                'product_variant_id' => $line['product_variant_id'],
                'non_stock_item_id' => $line['non_stock_item_id'],
            ],
            'requested_quantity' => $line['requested_quantity'],
            'quantity_to_source' => $line['shortage'],
            'confirmed_quantity' => $line['covered_quantity'],
            'supplier_confirmed_quantity' => $line['supplier_confirmed_quantity'],
            'manually_confirmed_quantity' => $line['manually_confirmed_quantity'],
            'remaining_quantity' => $remaining,
            'state' => $line['state'],
            'requests' => array_map(fn (array $request) => [
                'id' => $request['id'],
                'supplier' => $request['supplier_id'] ? ['id' => $request['supplier_id'], 'name' => $request['supplier_name']] : null,
                'requested_quantity' => (string) $request['requested_quantity'],
                'confirmed_quantity' => $request['confirmed_quantity'] === null ? null : (string) $request['confirmed_quantity'],
                'status' => $request['status'],
                'is_manual_confirmation' => $request['status'] === Status::ConfirmedManually->value,
                ...($this->canSeeNotes() ? ['note' => $request['note']] : []),
                'requested_at' => $request['requested_at'],
                'requested_by' => $request['requested_by'],
                'resolved_at' => $request['resolved_at'],
                'resolved_by' => $request['resolved_by'],
            ], $line['requests']),
        ];
    }

    /** Non-cancelled requests of a frozen quotation, for history. @return list<array<string, mixed>> */
    private function history(Quotation $quotation): array
    {
        return QuotationSupplierAvailabilityRequest::query()
            ->where('organization_id', $quotation->organization_id)
            ->where('quotation_id', $quotation->getKey())
            ->where('status', '!=', Status::Cancelled->value)
            ->orderBy('id')->limit(200)->get()
            ->map(fn (QuotationSupplierAvailabilityRequest $request) => $this->presentRequest($request))->all();
    }

    /** @return array{Quotation, QuotationLine} */
    private function quotationAndLine(int $quotationId, int $lineId): array
    {
        $quotation = $this->quotations->find($this->context->organization(), $quotationId);
        $line = $quotation->lines->firstWhere('id', $lineId)
            ?? throw (new ModelNotFoundException)->setModel(QuotationLine::class, [$lineId]);

        return [$quotation, $line];
    }

    /** The request must belong to this quotation, in a store the user can access. @return array{Quotation, QuotationSupplierAvailabilityRequest} */
    private function quotationAndRequest(int $quotationId, int $requestId): array
    {
        if ($requestId < 1) throw new InvalidArgumentException('invalid_id');
        $quotation = $this->quotations->find($this->context->organization(), $quotationId);
        $request = QuotationSupplierAvailabilityRequest::query()
            ->where('organization_id', $quotation->organization_id)
            ->where('quotation_id', $quotation->getKey())
            ->whereKey($requestId)
            ->first() ?? throw (new ModelNotFoundException)->setModel(QuotationSupplierAvailabilityRequest::class, [$requestId]);

        return [$quotation, $request];
    }

    /** Optional optimistic check: refuse to overwrite an answer recorded since the caller last read it. */
    private function guardExpected(QuotationSupplierAvailabilityRequest $request, ?string $expectedStatus): void
    {
        if ($expectedStatus !== null && $request->status->value !== $expectedStatus) {
            throw ValidationException::withMessages(['version' => 'The request changed since it was read.']);
        }
    }

    /** Internal notes are visible to people who edit quotations or manage procurement (same as the web editor). */
    private function canSeeNotes(): bool
    {
        $keys = $this->context->user()->permissionKeysFor($this->context->organization()->getKey());

        return array_intersect(['quotations.update', 'procurement.manage', 'procurement.view'], $keys) !== [];
    }
}
