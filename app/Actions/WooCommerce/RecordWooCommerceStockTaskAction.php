<?php

namespace App\Actions\WooCommerce;

use App\Enums\InventoryMovementType;
use App\Enums\WooCommerceStockTaskStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\ProductChannelIdentifier;
use App\Models\SalesOrder;
use App\Models\WooCommerceIntegration;
use App\Models\WooCommerceStockTask;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Part A of the manual WooCommerce stock bridge (see the class doc on
 * WooCommerceStockTask). Called from FulfillSalesOrderAction right after a
 * Sales reservation is consumed — today the only place a customer sale
 * actually reduces company stock (§ authoritative event).
 *
 * Deliberately NOT wired to every InventoryMovement:
 *  - TransferInventoryAction (TransferOut/TransferIn) moves stock between the
 *    organisation's own warehouses. WooCommerce represents organisation-wide
 *    sellable stock, so an internal transfer nets to zero from Woo's point of
 *    view and must never raise a -1/+1 pair of tasks.
 *  - SyncWooCommerceProductsAction's AdjustmentIn/AdjustmentOut reconciles the
 *    ERP FROM WooCommerce's own reported stock — creating a "please update
 *    WooCommerce" task from that would just echo the number back.
 *  - AdjustInventoryAction (manual opening/adjustment) is out of scope for V1
 *    per spec (§2): only the sale/stock-decrease workflow is covered.
 *
 * A no-op (returns null) unless the sold ProductVariant is actually linked to
 * a WooCommerce product/variation (via the existing product_channel_identifiers
 * mapping — no second mapping system) on an integration that has stock sync
 * enabled. No task is ever raised for a local-only article.
 */
class RecordWooCommerceStockTaskAction
{
    public function recordForConsumedSale(Organization $organization, SalesOrder $order, InventoryReservation $reservation): ?WooCommerceStockTask
    {
        $movement = InventoryMovement::query()
            ->where('organization_id', $organization->getKey())
            ->where('reference_type', InventoryReservation::class)
            ->where('reference_id', $reservation->getKey())
            ->where('movement_type', InventoryMovementType::ReservationConsumed)
            ->latest('id')
            ->first();

        if (! $movement) {
            return null;
        }

        $identifier = ProductChannelIdentifier::query()
            ->where('organization_id', $organization->getKey())
            ->where('source', WooCommerceIntegration::CHANNEL)
            ->where('product_variant_id', $movement->product_variant_id)
            ->with('integration')
            ->first();

        if (! $identifier || ! $identifier->integration || ! $identifier->integration->sync_stock) {
            return null;
        }

        return $this->createIdempotently($organization, $order, $movement, $identifier);
    }

    private function createIdempotently(
        Organization $organization,
        SalesOrder $order,
        InventoryMovement $movement,
        ProductChannelIdentifier $identifier,
    ): WooCommerceStockTask {
        return DB::transaction(function () use ($organization, $order, $movement, $identifier) {
            $existing = $this->findExisting($organization, $movement);
            if ($existing) {
                return $existing;
            }

            $variant = $movement->productVariant;

            $task = new WooCommerceStockTask;
            $task->organization_id = $organization->getKey();
            $task->store_id = $order->store_id;
            $task->product_id = $variant->product_id;
            $task->product_variant_id = $movement->product_variant_id;
            $task->warehouse_id = $movement->warehouse_id;
            $task->woocommerce_integration_id = $identifier->woocommerce_integration_id;
            $task->quantity_delta = $movement->quantity;
            $task->source_type = InventoryMovement::class;
            $task->source_id = $movement->getKey();
            $task->source_reference = $order->order_number;
            $task->reason = 'sales_order_fulfilled';
            $task->status = WooCommerceStockTaskStatus::Pending;
            $task->metadata = [
                'sales_order_id' => $order->getKey(),
                'quantity_before' => (string) $movement->quantity_before,
                'quantity_after' => (string) $movement->quantity_after,
                'external_product_id' => $identifier->external_product_id,
                'remote_variation_id' => $identifier->remote_variation_id,
            ];

            try {
                $task->save();
            } catch (QueryException $exception) {
                // Unique (organization_id, source_type, source_id) race: another
                // request already recorded this exact movement — reuse its row
                // rather than fail the caller (idempotency, §19).
                $existing = $this->findExisting($organization, $movement);
                if ($existing) {
                    return $existing;
                }

                throw $exception;
            }

            return $task;
        });
    }

    private function findExisting(Organization $organization, InventoryMovement $movement): ?WooCommerceStockTask
    {
        return WooCommerceStockTask::query()
            ->where('organization_id', $organization->getKey())
            ->where('source_type', InventoryMovement::class)
            ->where('source_id', $movement->getKey())
            ->first();
    }
}
