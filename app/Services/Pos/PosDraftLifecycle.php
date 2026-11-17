<?php

namespace App\Services\Pos;

use App\Enums\InventoryReservationStatus;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryReservationManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single owner of the POS draft lifecycle (activity tracking, eligibility,
 * safe discard). Reuses the existing reservation and audit services; there is
 * no parallel stock logic here.
 *
 * Inventory model (verified): a Draft Sales Order allocation does NOT reserve
 * stock; reservations are created at confirmation. Discarding a draft therefore
 * never touches on-hand or reserved balances. The only reservations a draft
 * could own are defensive edge cases (explicitly linked allocations); those are
 * released through InventoryReservationManager by an authenticated actor, and the
 * unattended scheduler refuses to touch them (reported as blocked instead).
 */
class PosDraftLifecycle
{
    public const REASON_MANUAL = 'manual';
    public const REASON_EMPTY = 'empty';
    /** The cashier left / switched away from an empty cart (event-driven, revalidated server-side). */
    public const REASON_ABANDONED = 'abandoned';
    /** A cancelled, never-confirmed POS draft deleted by an authorized user. */
    public const REASON_CANCELLED_DELETE = 'cancelled_delete';

    public const OUTCOME_DISCARDED = 'discarded';
    public const OUTCOME_SKIPPED_ACTIVE = 'skipped_active';
    public const OUTCOME_SKIPPED_GONE = 'skipped_not_open_draft';
    public const OUTCOME_BLOCKED = 'blocked';
    public const OUTCOME_CONFIRMATION_REQUIRED = 'confirmation_required';

    /** Relations whose rows mean the order has a consequential history. */
    private const HISTORY_RELATIONS = [
        'paymentAllocations' => 'payments',
        'paymentRefunds' => 'refunds',
        'expectedCollections' => 'finance_expectations',
        'commissionEntries' => 'commissions',
        'customerReturns' => 'returns',
        'customerExchanges' => 'exchanges',
        'invoices' => 'invoices',
        'revisions' => 'revisions',
        'addenda' => 'addenda',
        'deliveryNotes' => 'delivery_notes',
        'transferRequests' => 'transfers',
        'procurements' => 'procurements',
        'fulfillments' => 'fulfillments',
    ];

    public function __construct(
        private readonly InventoryReservationManager $reservations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Records a meaningful cart change. Written with a direct query so that
     * updated_at, observers and background refreshes are never involved.
     */
    public function touch(SalesOrder $order): void
    {
        SalesOrder::query()->posOpenDrafts()->whereKey($order->getKey())
            ->update(['pos_last_activity_at' => now()]);
    }

    public function inactivityCutoff(?Carbon $now = null): Carbon
    {
        return ($now ?? now())->copy()->subHours((int) config('pos.drafts.inactivity_hours', 24));
    }

    public function emptyGraceCutoff(?Carbon $now = null): Carbon
    {
        return ($now ?? now())->copy()->subMinutes((int) config('pos.drafts.empty_grace_minutes', 60));
    }

    /** Last verified activity; legacy rows (NULL) fall back to updated_at, then created_at. */
    public function lastActivityAt(SalesOrder $order): ?Carbon
    {
        return $order->pos_last_activity_at ?? $order->updated_at ?? $order->created_at;
    }

    public function isInactive(SalesOrder $order, int $lineCount, ?Carbon $now = null): bool
    {
        $last = $this->lastActivityAt($order);
        if ($last === null) {
            return false; // unknown activity is never treated as expired
        }
        $cutoff = $lineCount > 0 ? $this->inactivityCutoff($now) : $this->emptyGraceCutoff($now);

        return $last->lt($cutoff);
    }

    /** @return list<string> blocking reason codes; empty when the draft may be discarded. */
    public function blockers(SalesOrder $order, bool $allowReservationRelease = false): array
    {
        $reasons = [];
        foreach (self::HISTORY_RELATIONS as $relation => $code) {
            if ($order->{$relation}()->exists()) {
                $reasons[] = $code;
            }
        }
        if ($order->current_revision_id !== null) {
            $reasons[] = 'revisions';
        }
        if ($order->supplierAvailabilityRequests()->whereHas('derivedProcurement')->exists()) {
            $reasons[] = 'procurements';
        }
        if (! $allowReservationRelease && $this->activeReservationIds($order) !== []) {
            $reasons[] = 'active_reservations';
        }

        return array_values(array_unique($reasons));
    }

    /** @return list<int> Active reservation ids explicitly owned by this order's allocations. */
    public function activeReservationIds(SalesOrder $order): array
    {
        $ids = SalesOrderInventoryAllocation::query()
            ->whereIn('sales_order_line_id', $order->lines()->reorder()->select('id'))
            ->whereNotNull('inventory_reservation_id')
            ->pluck('inventory_reservation_id')->unique()->all();
        if ($ids === []) {
            return [];
        }

        return InventoryReservation::query()
            ->where('organization_id', $order->organization_id)
            ->whereIn('id', $ids)
            ->where('status', InventoryReservationStatus::Active->value)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Read-only classification used by the dry-run report.
     *
     * @return array{state: string, lines: int, reservations: int, reasons: list<string>}
     */
    public function classify(SalesOrder $order, ?Carbon $now = null): array
    {
        $lines = $order->lines()->count();
        $reservations = count($this->activeReservationIds($order));
        $blockers = $this->blockers($order);
        if ($blockers !== []) {
            return ['state' => 'blocked', 'lines' => $lines, 'reservations' => $reservations, 'reasons' => $blockers];
        }
        if (! $this->isInactive($order, $lines, $now)) {
            return ['state' => 'active', 'lines' => $lines, 'reservations' => $reservations, 'reasons' => []];
        }

        // A cart WITH products is never removed automatically: it only needs attention.
        return ['state' => $lines === 0 ? 'empty_eligible' : 'attention', 'lines' => $lines, 'reservations' => $reservations, 'reasons' => []];
    }

    /** True for a POS draft that holds products and has been idle past the inactivity window. */
    public function needsAttention(SalesOrder $order, int $lineCount, ?Carbon $now = null): bool
    {
        return $lineCount > 0
            && $order->source === \App\Enums\SalesOrderSource::Pos
            && $order->status === \App\Enums\SalesOrderStatus::Draft
            && $order->pos_converted_at === null
            && $this->isInactive($order, $lineCount, $now);
    }

    /** Candidate query for the current environment (Production unless a Test context is bound). */
    public function candidates(?Organization $organization = null): Builder
    {
        return SalesOrder::query()->posOpenDrafts()
            ->when($organization, fn (Builder $q) => $q->where('organization_id', $organization->getKey()));
    }

    /**
     * Discards one POS draft atomically. Re-validates everything under a row lock
     * so edit / confirm / payment / convert and cleanup cannot interleave.
     *
     * @param  User|null  $actor  null only for the unattended scheduler
     * @return array{outcome: string, reasons?: list<string>, lines?: int}
     */
    public function discard(?User $actor, SalesOrder $order, string $reason, bool $confirmed = false): array
    {
        try {
            return DB::transaction(function () use ($actor, $order, $reason, $confirmed) {
                return $this->discardLocked($actor, $order, $reason, $confirmed);
            });
        } catch (QueryException) {
            // A concurrent request attached a child row (e.g. a line added in another tab) between
            // our checks and the delete: the FK refused it, nothing was removed. Never an error.
            return ['outcome' => self::OUTCOME_SKIPPED_ACTIVE];
        }
    }

    /**
     * Event-driven cleanup: removes the terminal's EMPTY open carts other than $exceptId whose last
     * activity is older than $graceSeconds. Every candidate is revalidated under its row lock by
     * discard(); safe to call repeatedly (idempotent) and from several requests at once.
     */
    public function abandonEmptyCarts(User $actor, Organization $organization, \App\Models\Store $store, ?string $terminal, ?int $exceptId = null, int $graceSeconds = 0): int
    {
        $cutoff = now()->subSeconds(max(0, $graceSeconds));
        $ids = $this->candidates($organization)
            ->where('store_id', $store->getKey())
            ->when($terminal, fn (Builder $q, string $id) => $q->where('pos_terminal_id', $id), fn (Builder $q) => $q->whereNull('pos_terminal_id'))
            ->when($exceptId, fn (Builder $q, int $id) => $q->whereKeyNot($id))
            ->whereDoesntHave('lines')
            ->whereRaw('COALESCE(pos_last_activity_at, updated_at, created_at) <= ?', [$cutoff])
            ->limit(25)->pluck('id');

        $removed = 0;
        foreach ($ids as $id) {
            $candidate = SalesOrder::query()->posOpenDrafts()->find($id);
            if ($candidate && $this->discard($actor, $candidate, self::REASON_ABANDONED)['outcome'] === self::OUTCOME_DISCARDED) {
                $removed++;
            }
        }

        return $removed;
    }

    /** @return array{outcome: string, reasons?: list<string>, lines?: int} */
    private function discardLocked(?User $actor, SalesOrder $order, string $reason, bool $confirmed): array
    {
        $query = $reason === self::REASON_CANCELLED_DELETE
            ? SalesOrder::query()
                ->where('source', \App\Enums\SalesOrderSource::Pos->value)
                ->where('status', \App\Enums\SalesOrderStatus::Cancelled->value)
                ->whereNull('pos_converted_at')
            : SalesOrder::query()->posOpenDrafts();
        $locked = $query->where('organization_id', $order->organization_id)
            ->whereKey($order->getKey())->lockForUpdate()->first();
        if (! $locked) {
            return ['outcome' => self::OUTCOME_SKIPPED_GONE];
        }

        $lineCount = $locked->lines()->count();
        if ($actor === null && $lineCount > 0) {
            // The unattended scheduler NEVER removes a cart that holds products (history is reported precisely).
            $history = $this->blockers($locked);

            return $history !== [] ? ['outcome' => self::OUTCOME_BLOCKED, 'reasons' => $history] : ['outcome' => self::OUTCOME_SKIPPED_ACTIVE];
        }
        if ($reason === self::REASON_EMPTY) {
            if ($lineCount > 0 || ! $this->isInactive($locked, $lineCount)) {
                return ['outcome' => self::OUTCOME_SKIPPED_ACTIVE];
            }
        } elseif ($reason === self::REASON_ABANDONED) {
            if ($actor === null || $lineCount > 0) {
                return ['outcome' => self::OUTCOME_SKIPPED_ACTIVE];
            }
        } elseif ($reason === self::REASON_CANCELLED_DELETE) {
            if ($actor === null || $locked->confirmed_at !== null) {
                return ['outcome' => self::OUTCOME_BLOCKED, 'reasons' => ['confirmed']];
            }
        } elseif ($lineCount > 0 && ! $confirmed) {
            return ['outcome' => self::OUTCOME_CONFIRMATION_REQUIRED, 'lines' => $lineCount];
        }

        {
            $blockers = $this->blockers($locked, allowReservationRelease: $actor !== null);
            if ($blockers !== []) {
                return ['outcome' => self::OUTCOME_BLOCKED, 'reasons' => $blockers];
            }

            // Only reservations owned by THIS draft's allocations; never a double release.
            $released = [];
            if ($actor !== null) {
                $owned = InventoryReservation::query()
                    ->where('organization_id', $locked->organization_id)
                    ->whereIn('id', $this->activeReservationIds($locked))
                    ->with(['warehouse', 'productVariant'])
                    ->orderBy('warehouse_id')->orderBy('product_variant_id')->get();
                foreach ($owned as $reservation) {
                    $this->reservations->release($actor, $locked->organization, $reservation);
                    $released[] = (int) $reservation->getKey();
                }
            }

            $lines = $locked->lines()->get();
            $snapshot = [
                'order_number' => $locked->order_number,
                'reason' => $reason,
                'trigger' => $actor ? 'user' : 'scheduler',
                'line_count' => $lineCount,
                'total_incl_tax' => $locked->total_incl_tax,
                'customer_id' => $locked->customer_id,
                'pos_terminal_id' => $locked->pos_terminal_id,
                'last_activity_at' => $this->lastActivityAt($locked)?->toIso8601String(),
                'released_reservation_ids' => $released,
                'lines' => $lines->map(fn ($line) => [
                    'product_variant_id' => $line->product_variant_id,
                    'quantity' => (string) $line->quantity,
                    'line_type' => $line->line_type,
                ])->values()->all(),
            ];

            foreach ($lines as $line) {
                $line->allocations()->delete();
                $line->supplierAvailabilityRequests()->whereDoesntHave('derivedProcurement')->delete();
                $line->delete();
            }
            $this->audit->record('sales_order.pos_draft_discarded', $actor, $locked->organization, $locked->store, $locked, oldValues: ['status' => $locked->status->value], newValues: $snapshot);
            $locked->delete();

            return ['outcome' => self::OUTCOME_DISCARDED, 'lines' => $lineCount];
        }
    }
}
