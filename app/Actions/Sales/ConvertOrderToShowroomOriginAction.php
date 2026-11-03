<?php

namespace App\Actions\Sales;

use App\Actions\Inventory\CreateOrderTransferRequestsAction;
use App\Enums\FulfillmentStatus;
use App\Enums\InventoryReservationStatus;
use App\Enums\PosFulfillmentOrigin;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Enums\TransferRequestStatus;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ShowroomReplenishmentPlanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Explicit, audited repair: switch an already-confirmed POS order from
 * DIRECT dispatch to SHOWROOM origin and let the canonical planner
 * ({@see CreateOrderTransferRequestsAction}) recompute its transfers from the
 * current, authoritative allocations and reservations.
 *
 * For orders confirmed before the origin could be chosen (e.g. a delivery
 * order whose goods should in fact be gathered at the Showroom). Never runs
 * automatically. Refuses — explaining why — as soon as goods have started to
 * move: any shipment / delivery note, a customer request already in
 * preparation or shipped, or a customer allocation without an active
 * reservation. Reservations are preserved as they are (none created, none
 * released); the planner only adds the protected customer transfer line,
 * reconciles open general replenishment (moved, never duplicated) and tops up
 * the minimum within real transferable stock.
 *
 * `$apply = false` (preview) runs the exact same code inside a transaction
 * that is rolled back, so the reported "after" is what applying would do.
 */
class ConvertOrderToShowroomOriginAction
{
    public function __construct(
        private readonly CreateOrderTransferRequestsAction $transferRequests,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{order: string, applied: bool, before: list<array<string, mixed>>, after: list<array<string, mixed>>} */
    public function execute(User $actor, SalesOrder $order, bool $apply): array
    {
        abort_unless(
            $actor->hasPermission($order->organization_id, 'sales_orders.update')
            && $actor->hasPermission($order->organization_id, 'inventory.transfer_requests.manage'),
            403,
        );

        DB::beginTransaction();
        try {
            $order = SalesOrder::query()->withoutGlobalScopes()
                ->where('organization_id', $order->organization_id)
                ->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->assertConvertible($order);

            $before = $this->snapshot($order);
            $previous = $order->pos_fulfillment_origin?->value;
            $order->pos_fulfillment_origin = PosFulfillmentOrigin::Showroom;
            $order->save();

            $this->transferRequests->createForConfirmedOrder($actor, $order->fresh(['lines.allocations', 'organization', 'store']));

            $after = $this->snapshot($order);
            $this->audit->record('sales_order.fulfillment_origin_converted', $actor, $order->organization, $order->store, $order,
                oldValues: ['fulfillment_origin' => $previous, 'effective_origin' => PosFulfillmentOrigin::Direct->value, 'transfer_requests' => $before],
                newValues: ['order_number' => $order->order_number, 'fulfillment_origin' => PosFulfillmentOrigin::Showroom->value, 'transfer_requests' => $after],
            );

            $apply ? DB::commit() : DB::rollBack();
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        return ['order' => $order->order_number, 'applied' => $apply, 'before' => $before, 'after' => $after];
    }

    private function assertConvertible(SalesOrder $order): void
    {
        $refuse = fn (string $message) => throw ValidationException::withMessages(['order' => "{$order->order_number} : {$message}"]);

        if ($order->source !== SalesOrderSource::Pos || (int) $order->pos_warehouse_id === 0) {
            $refuse('seules les commandes POS rattachées à un Showroom peuvent être préparées depuis le Showroom.');
        }
        if ($order->status !== SalesOrderStatus::Confirmed) {
            $refuse('la commande doit être confirmée.');
        }
        if ($order->fulfillmentOrigin() === PosFulfillmentOrigin::Showroom) {
            $refuse('la commande est déjà préparée depuis le Showroom ; rien à convertir.');
        }
        if ($order->fulfillment_status !== SalesOrderFulfillmentStatus::Unfulfilled
            || $order->fulfillments()->where('status', '!=', FulfillmentStatus::Cancelled->value)->exists()
            || $order->deliveryNotes()->exists()) {
            $refuse('une expédition, une remise ou un bon de livraison existe déjà : la marchandise a commencé à sortir, conversion refusée.');
        }
        $started = $order->transferRequests()
            ->whereIn('status', [TransferRequestStatus::Preparing->value, TransferRequestStatus::Shipped->value, TransferRequestStatus::Received->value])
            ->pluck('request_number');
        if ($started->isNotEmpty()) {
            $refuse('la demande de transfert '.$started->implode(', ').' est déjà en préparation, expédiée ou réceptionnée.');
        }
        $unreserved = SalesOrderInventoryAllocation::query()
            ->join('sales_order_lines as l', 'l.id', '=', 'sales_order_inventory_allocations.sales_order_line_id')
            ->leftJoin('inventory_reservations as r', 'r.id', '=', 'sales_order_inventory_allocations.inventory_reservation_id')
            ->where('sales_order_inventory_allocations.organization_id', $order->organization_id)
            ->where('l.sales_order_id', $order->getKey())
            ->where(fn ($q) => $q->whereNull('r.id')->orWhere('r.status', '!=', InventoryReservationStatus::Active->value))
            ->exists();
        if ($unreserved) {
            $refuse('une allocation client n’a plus de réservation active : vérifiez la commande avant toute conversion.');
        }
    }

    /**
     * Every active transfer request into the order's Showroom (or linked to
     * the order) that carries one of the order's variants, with those lines.
     *
     * @return list<array<string, mixed>>
     */
    private function snapshot(SalesOrder $order): array
    {
        $variantIds = $order->lines()->whereNotNull('product_variant_id')->pluck('product_variant_id')->map(fn ($id) => (int) $id)->all();

        return TransferRequest::query()
            ->where('organization_id', $order->organization_id)
            ->where(fn ($q) => $q->where('sales_order_id', $order->getKey())
                ->orWhere(fn ($q) => $q->where('destination_warehouse_id', $order->pos_warehouse_id)
                    ->whereIn('status', ShowroomReplenishmentPlanner::ACTIVE_STATUSES)))
            ->whereHas('lines', fn ($q) => $q->whereIn('product_variant_id', $variantIds))
            ->with(['lines' => fn ($q) => $q->whereIn('product_variant_id', $variantIds)->orderBy('id')])
            ->orderBy('id')->get()
            ->map(fn (TransferRequest $request) => [
                'request_number' => $request->request_number,
                'status' => $request->status->value,
                'sales_order_id' => $request->sales_order_id,
                'lines' => $request->lines->map(fn ($line) => [
                    'product_variant_id' => (int) $line->product_variant_id,
                    'reason' => $line->reason->value,
                    'quantity' => $line->quantity,
                ])->all(),
            ])->all();
    }
}
