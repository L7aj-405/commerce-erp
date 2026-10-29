<?php

namespace App\Services\Fulfillment;

use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Actions\WooCommerce\RecordWooCommerceStockTaskAction;
use App\Enums\FulfillmentStatus;
use App\Enums\InventoryReservationStatus;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\SalesOrderLineType;
use App\Enums\SalesOrderStatus;
use App\Models\Fulfillment;
use App\Models\FulfillmentItem;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryReservationManager;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F1.1 fulfillment engine: the shared rules behind every fulfillment action
 * (App\Actions\Fulfillment) and the direct hand-over of FulfillSalesOrderAction.
 *
 * - One engine, two organization modes (simple / advanced) — the mode only
 *   decides which transitions are offered.
 * - Lock order: Sales Order row, then fulfillment row, then (inside the
 *   inventory manager) balances and reservations. Line quantities are checked
 *   under the Sales Order lock, so concurrent shipments can never overship.
 * - Stock leaves only when a fulfillment is handed over (stock_consumed_at is
 *   set once, under lock): the exact item quantities are consumed from the
 *   line's active allocation reservations through InventoryReservationManager
 *   (whole consume, or consumeQuantity for a part, shrinking the allocation so
 *   "allocation = active reservation" keeps holding for transfers/procurement).
 * - sales_orders.fulfillment_status stays the single stored summary
 *   (unfulfilled / partially_fulfilled / fulfilled = goods left the company),
 *   recomputed here from the shipped items; delivery is derived on read.
 */
final class FulfillmentEngine
{
    use AuthorizesSalesAction;

    public function __construct(
        private readonly InventoryReservationManager $inventory,
        private readonly RecordWooCommerceStockTaskAction $wooStockTasks,
        private readonly AuditLogger $audit,
    ) {}

    public static function modeFor(Organization $organization): string
    {
        $mode = is_array($organization->settings) ? ($organization->settings['fulfillment_mode'] ?? null) : null;

        return in_array($mode, Fulfillment::MODES, true) ? $mode : Fulfillment::MODE_SIMPLE;
    }

    public function authorize(User $actor, SalesOrder $order, string ...$permissions): void
    {
        foreach ($permissions as $permission) $this->authorizeOrder($actor, $order, $permission);
    }

    /** At least one of the permissions (e.g. proofs may be attached by dispatchers or managers). */
    public function authorizeAny(User $actor, SalesOrder $order, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if ($actor->hasPermission($order->organization_id, $permission)) {
                $this->authorizeOrder($actor, $order, $permission);

                return;
            }
        }
        abort(403);
    }

    /** Row-locks a confirmed Sales Order of the same organization and store. */
    public function lockOrder(SalesOrder $order): SalesOrder
    {
        $locked = SalesOrder::query()->where('organization_id', $order->organization_id)->where('store_id', $order->store_id)
            ->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== SalesOrderStatus::Confirmed) {
            throw ValidationException::withMessages(['order' => 'Seule une commande confirmée peut être expédiée.']);
        }

        return $locked;
    }

    /** Row-locks a fulfillment of this order; a stale expected status is refused. */
    public function lockFulfillment(SalesOrder $order, Fulfillment $fulfillment, ?string $expectedStatus = null): Fulfillment
    {
        $locked = Fulfillment::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())
            ->whereKey($fulfillment->getKey())->lockForUpdate()->firstOrFail();
        if ($expectedStatus !== null && $locked->status->value !== $expectedStatus) {
            throw ValidationException::withMessages(['status' => 'Cette expédition a changé entre-temps : actualisez la page.']);
        }

        return $locked;
    }

    /** Goods may leave only once internal transfers for this order are received (as for the direct hand-over). */
    public function assertDispatchable(SalesOrder $order): void
    {
        if ($order->awaitingReplenishment()) {
            throw ValidationException::withMessages(['order' => 'À approvisionner : un transfert interne doit être réceptionné avant l’expédition.']);
        }
    }

    /** @param list<FulfillmentStatus> $from */
    public function assertTransition(Fulfillment $fulfillment, array $from, string $message): void
    {
        if (! in_array($fulfillment->status, $from, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /**
     * Per line: quantity, planned (items of non-cancelled fulfillments),
     * shipped, and for catalog lines the active reserved stock and the part of
     * it already claimed by unshipped fulfillments.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lineState(SalesOrder $order, ?string $excludeFulfillmentId = null): array
    {
        $lines = SalesOrderLine::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())
            ->orderBy('id')->get();
        $items = FulfillmentItem::query()->from('fulfillment_items as fi')
            ->join('fulfillments as f', fn ($join) => $join->on('f.id', '=', 'fi.fulfillment_id')->on('f.organization_id', '=', 'fi.organization_id'))
            ->where('fi.organization_id', $order->organization_id)->where('f.sales_order_id', $order->getKey())
            ->where('f.status', '!=', FulfillmentStatus::Cancelled->value)
            ->get(['fi.sales_order_line_id', 'fi.quantity', 'f.status', 'f.id as fulfillment_id']);
        $reserved = SalesOrderInventoryAllocation::query()->from('sales_order_inventory_allocations as a')
            ->join('inventory_reservations as r', fn ($join) => $join->on('r.id', '=', 'a.inventory_reservation_id')->on('r.organization_id', '=', 'a.organization_id'))
            ->where('a.organization_id', $order->organization_id)->whereIn('a.sales_order_line_id', $lines->pluck('id'))
            ->where('r.status', InventoryReservationStatus::Active->value)
            ->get(['a.sales_order_line_id', 'r.quantity'])
            ->groupBy('sales_order_line_id')
            ->map(fn ($rows) => $rows->reduce(fn (string $sum, $row) => InventoryQuantity::add($sum, (string) $row->quantity), InventoryQuantity::ZERO));

        $state = [];
        foreach ($lines as $line) {
            $lineItems = $items->where('sales_order_line_id', $line->getKey());
            $sum = fn ($rows) => $rows->reduce(fn (string $carry, $row) => InventoryQuantity::add($carry, (string) $row->quantity), InventoryQuantity::ZERO);
            $counted = $lineItems->filter(fn ($row) => $row->fulfillment_id !== $excludeFulfillmentId);
            $unshipped = $counted->filter(fn ($row) => ! FulfillmentStatus::from($row->status)->isShipped());
            $state[(int) $line->getKey()] = [
                'line' => $line,
                'catalog' => $line->line_type === SalesOrderLineType::Catalog && $line->product_variant_id !== null,
                'quantity' => InventoryQuantity::normalize((string) $line->quantity),
                'planned' => $sum($counted),
                'shipped' => $sum($lineItems->filter(fn ($row) => FulfillmentStatus::from($row->status)->isShipped())),
                'delivered' => $sum($lineItems->filter(fn ($row) => FulfillmentStatus::from($row->status)->isDelivered())),
                'reserved' => $reserved[$line->getKey()] ?? InventoryQuantity::ZERO,
                'claimed_unshipped' => $sum($unshipped),
            ];
        }

        return $state;
    }

    /**
     * Validates requested quantities (line id => quantity) against what is
     * left to ship and, for stock lines, the reserved stock not yet claimed.
     *
     * @param array<int|string, mixed> $requested
     * @return array<int, string> line id => normalized quantity
     */
    public function validateItems(SalesOrder $order, array $requested, ?string $excludeFulfillmentId = null): array
    {
        $state = $this->lineState($order, $excludeFulfillmentId);
        $items = [];
        $errors = [];
        foreach ($requested as $lineId => $quantity) {
            if ($quantity === null || $quantity === '' || (is_numeric($quantity) && (float) $quantity === 0.0)) continue;
            $key = "items.{$lineId}";
            $line = $state[(int) $lineId] ?? null;
            if ($line === null) {
                $errors[$key] = 'Cette ligne n’appartient pas à la commande.';
                continue;
            }
            try {
                $quantity = InventoryQuantity::positive((string) $quantity, $key);
            } catch (ValidationException) {
                $errors[$key] = 'La quantité doit être un nombre positif.';
                continue;
            }
            $left = InventoryQuantity::subtract($line['quantity'], $line['planned']);
            if (InventoryQuantity::compare($quantity, $left) > 0) {
                $errors[$key] = 'Il ne reste que '.$left.' à expédier pour cette ligne.';
                continue;
            }
            if ($line['catalog']) {
                $free = InventoryQuantity::subtract($line['reserved'], $line['claimed_unshipped']);
                if (InventoryQuantity::compare($quantity, $free) > 0) {
                    $errors[$key] = 'Stock réservé disponible pour cette ligne : '.$free.'.';
                    continue;
                }
            }
            $items[(int) $lineId] = $quantity;
        }
        if ($errors !== []) throw ValidationException::withMessages($errors);
        if ($items === []) throw ValidationException::withMessages(['items' => 'Indiquez au moins une quantité à expédier.']);

        return $items;
    }

    /** Everything left to ship (line id => quantity), nothing claimed by another fulfillment. @return array<int, string> */
    public function remaining(SalesOrder $order): array
    {
        $remaining = [];
        foreach ($this->lineState($order) as $lineId => $line) {
            $left = InventoryQuantity::subtract($line['quantity'], $line['planned']);
            if (InventoryQuantity::compare($left, InventoryQuantity::ZERO) > 0) $remaining[$lineId] = $left;
        }

        return $remaining;
    }

    /**
     * @param array<int, string> $items line id => quantity (already validated)
     * @param array<string, mixed> $attributes
     */
    public function create(User $actor, SalesOrder $order, array $items, FulfillmentStatus $status, string $channel, array $attributes = [], ?string $clientOperationId = null): Fulfillment
    {
        $fulfillment = new Fulfillment;
        $fulfillment->forceFill([
            ...$attributes,
            'organization_id' => $order->organization_id,
            'store_id' => $order->store_id,
            'sales_order_id' => $order->getKey(),
            'status' => $status,
            'workflow_mode' => self::modeFor($order->organization),
            'channel' => $channel,
            'client_operation_id' => $clientOperationId,
            'created_by_user_id' => $actor->getKey(),
        ])->save();
        $lines = SalesOrderLine::query()->where('organization_id', $order->organization_id)->whereIn('id', array_keys($items))->get()->keyBy('id');
        foreach ($items as $lineId => $quantity) {
            $item = new FulfillmentItem;
            $item->forceFill([
                'organization_id' => $order->organization_id,
                'fulfillment_id' => $fulfillment->getKey(),
                'sales_order_line_id' => $lineId,
                'product_variant_id' => $lines[$lineId]?->product_variant_id,
                'quantity' => $quantity,
            ])->save();
        }
        $this->record('fulfillment.created', $actor, $order, $fulfillment, [], [
            'status' => $status->value, 'workflow_mode' => $fulfillment->workflow_mode, 'channel' => $channel,
            'items' => array_map(fn ($lineId, $quantity) => ['sales_order_line_id' => $lineId, 'quantity' => $quantity], array_keys($items), $items),
        ]);

        return $fulfillment;
    }

    /**
     * Consumes exactly the items' quantities from the lines' active allocation
     * reservations — once: a fulfillment whose stock was consumed is skipped.
     *
     * @return list<int> inventory movement-owning reservation ids
     */
    public function consumeStock(User $actor, SalesOrder $order, Fulfillment $fulfillment): array
    {
        if ($fulfillment->stock_consumed_at !== null) return [];
        $consumed = [];
        $items = $fulfillment->items()->orderBy('id')->get();
        foreach ($items as $item) {
            $line = SalesOrderLine::query()->where('organization_id', $order->organization_id)->whereKey($item->sales_order_line_id)->firstOrFail();
            if ($line->line_type !== SalesOrderLineType::Catalog || $line->product_variant_id === null) continue;
            $remaining = InventoryQuantity::normalize((string) $item->quantity);
            $allocations = SalesOrderInventoryAllocation::query()->where('organization_id', $order->organization_id)
                ->where('sales_order_line_id', $line->getKey())->whereNotNull('inventory_reservation_id')
                ->orderBy('warehouse_id')->orderBy('id')->lockForUpdate()->with('inventoryReservation.warehouse', 'inventoryReservation.productVariant')->get();
            foreach ($allocations as $allocation) {
                if (InventoryQuantity::compare($remaining, InventoryQuantity::ZERO) <= 0) break;
                $reservation = $allocation->inventoryReservation;
                if ($reservation === null || $reservation->status !== InventoryReservationStatus::Active) continue;
                $take = InventoryQuantity::compare($remaining, $reservation->quantity) < 0 ? $remaining : InventoryQuantity::normalize((string) $reservation->quantity);
                if (InventoryQuantity::compare($take, $reservation->quantity) === 0) {
                    $result = $this->inventory->consume($actor, $order->organization, $reservation);
                } else {
                    $result = $this->inventory->consumeQuantity($actor, $order->organization, $reservation, $take, FulfillmentItem::class, (int) $item->getKey(), $order->order_number);
                    $allocation->quantity = InventoryQuantity::subtract((string) $allocation->quantity, $take);
                    $allocation->save();
                }
                // Manual WooCommerce bridge: a no-op unless the variant is Woo-linked.
                $this->wooStockTasks->recordForConsumedSale($order->organization, $order, $result);
                $consumed[] = (int) $result->getKey();
                $remaining = InventoryQuantity::subtract($remaining, $take);
            }
            if (InventoryQuantity::compare($remaining, InventoryQuantity::ZERO) > 0) {
                throw ValidationException::withMessages(["items.{$line->getKey()}" => 'Le stock réservé de cette ligne ne couvre pas la quantité expédiée.']);
            }
        }
        $fulfillment->stock_consumed_at = now();
        $fulfillment->save();

        return $consumed;
    }

    /** Recomputes sales_orders.fulfillment_status from shipped items. */
    public function syncOrder(User $actor, SalesOrder $order): SalesOrder
    {
        $state = $this->lineState($order);
        $relevant = array_filter($state, fn (array $line) => InventoryQuantity::compare($line['quantity'], InventoryQuantity::ZERO) > 0);
        $complete = $relevant !== [] && collect($relevant)->every(fn (array $line) => InventoryQuantity::compare($line['shipped'], $line['quantity']) >= 0);
        $any = collect($relevant)->contains(fn (array $line) => InventoryQuantity::compare($line['shipped'], InventoryQuantity::ZERO) > 0);
        $status = $complete ? SalesOrderFulfillmentStatus::Fulfilled : ($any ? SalesOrderFulfillmentStatus::PartiallyFulfilled : SalesOrderFulfillmentStatus::Unfulfilled);
        $previous = $order->fulfillment_status;
        if ($previous === $status) return $order;

        $order->fulfillment_status = $status;
        if ($status === SalesOrderFulfillmentStatus::Fulfilled) {
            $order->fulfilled_at = now();
            $order->fulfilled_by_user_id = $actor->getKey();
        }
        $order->save();
        if ($status === SalesOrderFulfillmentStatus::Fulfilled) {
            $this->audit->record('sales_order.fulfilled', $actor, $order->organization, $order->store, $order, oldValues: ['fulfillment_status' => $previous->value], newValues: [
                'order_number' => $order->order_number, 'fulfillment_status' => $status->value, 'total_incl_tax' => $order->total_incl_tax,
            ]);
        }

        return $order;
    }

    /** Canonical fulfillment audit event with its Sales Order context. @param array<string, mixed> $old @param array<string, mixed> $new */
    public function record(string $event, User $actor, SalesOrder $order, Fulfillment $fulfillment, array $old, array $new): void
    {
        $this->audit->record($event, $actor, $order->organization, $order->store, $fulfillment, oldValues: $old, newValues: [
            'fulfillment_id' => (string) $fulfillment->getKey(),
            'sales_order_id' => (int) $order->getKey(),
            'order_number' => $order->order_number,
            ...$new,
        ]);
    }

    /** Runs a mutation in one transaction with the Sales Order lock taken first. @template T @param callable(SalesOrder): T $callback @return T */
    public function transaction(SalesOrder $order, callable $callback): mixed
    {
        return DB::transaction(fn () => $callback($this->lockOrder($order)));
    }
}
