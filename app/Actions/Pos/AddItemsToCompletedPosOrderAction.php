<?php

namespace App\Actions\Pos;

use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Actions\WooCommerce\RecordWooCommerceStockTaskAction;
use App\Enums\CatalogStatus;
use App\Enums\SalesOrderDiscountType;
use App\Enums\SalesOrderLineType;
use App\Enums\WarehouseStatus;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderAddendum;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\InventoryReservationManager;
use App\Services\Pos\PosOrderCompletionEligibility;
use App\Services\ProductPriceResolver;
use App\Services\SalesLineCalculator;
use App\Services\SalesOrderPaymentCalculator;
use App\Services\SalesOrderTotalsCalculator;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Narrow post-checkout POS operation. It appends immutable line/addendum rows;
 * it never reopens, edits, re-reserves, or re-fulfils historical quantities.
 */
class AddItemsToCompletedPosOrderAction
{
    use AuthorizesSalesAction;

    public function __construct(
        private readonly ProductPriceResolver $prices,
        private readonly SalesLineCalculator $lines,
        private readonly SalesOrderTotalsCalculator $totals,
        private readonly SalesOrderPaymentCalculator $payments,
        private readonly InventoryReservationManager $inventory,
        private readonly RecordWooCommerceStockTaskAction $wooStockTasks,
        private readonly AuditLogger $audit,
        private readonly PosOrderCompletionEligibility $eligibility,
    ) {}

    /** @param list<array{product_variant_id: int, quantity: string}> $items */
    public function execute(User $actor, SalesOrder $order, string $operationId, array $items): SalesOrderAddendum
    {
        $this->authorizeOrder($actor, $order, 'sales_orders.update');
        abort_unless(
            $actor->hasPermission($order->organization_id, 'pos.access')
            && $actor->hasPermission($order->organization_id, 'sales_orders.fulfill'),
            403,
        );

        $operationHash = $this->operationHash($items);

        try {
            return DB::transaction(function () use ($actor, $order, $operationId, $operationHash, $items) {
                $order = SalesOrder::query()
                    ->where('organization_id', $order->organization_id)
                    ->where('store_id', $order->store_id)
                    ->whereKey($order->getKey())
                    ->lockForUpdate()
                    ->with(['organization', 'store', 'posWarehouse'])
                    ->firstOrFail();

                if ($existing = $this->existing($order, $operationId, $operationHash)) {
                    return $existing;
                }

                $this->eligibility->assertAllowed($actor, $order);
                $warehouse = $this->localWarehouse($order);
                $beforeTotal = (string) $order->total_incl_tax;

                $addendum = new SalesOrderAddendum;
                $addendum->organization_id = $order->organization_id;
                $addendum->store_id = $order->store_id;
                $addendum->sales_order_id = $order->getKey();
                $addendum->sequence = ((int) $order->addenda()->max('sequence')) + 1;
                $addendum->client_operation_id = $operationId;
                $addendum->operation_hash = $operationHash;
                $addendum->before_total = $beforeTotal;
                $addendum->added_total = '0.0000';
                $addendum->after_total = $beforeTotal;
                $addendum->created_by_user_id = $actor->getKey();
                $addendum->save();

                $position = (int) $order->lines()->max('position');
                $addedTotal = '0.0000';
                $auditLines = [];

                foreach ($items as $item) {
                    $variant = ProductVariant::query()
                        ->where('organization_id', $order->organization_id)
                        ->whereKey($item['product_variant_id'])
                        ->with(['product.defaultUnit', 'taxRate'])
                        ->firstOrFail();
                    if ($variant->status !== CatalogStatus::Active || $variant->product->status !== CatalogStatus::Active) {
                        throw ValidationException::withMessages(['lines' => 'Seuls les articles actifs peuvent être ajoutés.']);
                    }

                    $price = $this->prices->resolve($variant, $order->store);
                    if ($price['config_missing'] || $price['unit_price_ht'] === null) {
                        throw ValidationException::withMessages([
                            'lines' => "La TVA ou le prix HT de {$variant->product->name} n’est pas configuré.",
                        ]);
                    }
                    $calculated = $this->lines->calculate(
                        $item['quantity'],
                        $price['unit_price_ht'],
                        $price['tax_rate_value'],
                        SalesOrderDiscountType::None,
                        '0',
                    );

                    $line = new SalesOrderLine;
                    $line->organization_id = $order->organization_id;
                    $line->sales_order_id = $order->getKey();
                    $line->sales_order_addendum_id = $addendum->getKey();
                    $line->line_type = SalesOrderLineType::Catalog;
                    $line->position = ++$position;
                    $line->product_variant_id = $variant->getKey();
                    $line->product_name = $variant->product->name;
                    $line->variant_name = $variant->label;
                    $line->sku = $variant->sku;
                    $line->reference = $variant->reference;
                    $line->unit_label = $variant->product->defaultUnit?->symbol ?? $variant->product->defaultUnit?->name;
                    $line->tax_name = $price['tax_name'];
                    $line->tax_unresolved = false;
                    $line->discount_type = SalesOrderDiscountType::None;
                    foreach ($calculated as $field => $value) {
                        $line->{$field} = $value;
                    }
                    $line->save();

                    $allocation = new SalesOrderInventoryAllocation;
                    $allocation->organization_id = $order->organization_id;
                    $allocation->sales_order_line_id = $line->getKey();
                    $allocation->warehouse_id = $warehouse->getKey();
                    $allocation->quantity = $calculated['quantity'];
                    $allocation->save();

                    // Local-only V1: reserve and consume only this new quantity.
                    // The allocation id is the idempotent inventory reference.
                    $reservation = $this->inventory->reserve(
                        $actor,
                        $order->organization,
                        $warehouse,
                        $variant,
                        $calculated['quantity'],
                        SalesOrderInventoryAllocation::class,
                        $allocation->getKey(),
                        $order->order_number.' · complément #'.$addendum->sequence,
                    );
                    $allocation->inventory_reservation_id = $reservation->getKey();
                    $allocation->save();
                    $reservation = $this->inventory->consume($actor, $order->organization, $reservation);
                    $this->wooStockTasks->recordForConsumedSale($order->organization, $order, $reservation);

                    $addedTotal = Decimal::add($addedTotal, $calculated['total_incl_tax']);
                    $auditLines[] = [
                        'sales_order_line_id' => $line->getKey(),
                        'product_variant_id' => $variant->getKey(),
                        'quantity' => $calculated['quantity'],
                    ];
                }

                $this->totals->recalculate($order);
                $this->payments->recalculate($order->fresh());
                $order->refresh();

                $addendum->added_total = $addedTotal;
                $addendum->after_total = $order->total_incl_tax;
                $addendum->fulfilled_at = now();
                $addendum->save();

                $this->audit->record('sales_order.items_added', $actor, $order->organization, $order->store, $order, newValues: [
                    'sales_order_id' => $order->getKey(),
                    'order_number' => $order->order_number,
                    'addendum_id' => $addendum->getKey(),
                    'addendum_sequence' => $addendum->sequence,
                    'client_operation_id' => $operationId,
                    'before_total' => $beforeTotal,
                    'added_total' => $addedTotal,
                    'after_total' => $order->total_incl_tax,
                    'warehouse_id' => $warehouse->getKey(),
                    'lines' => $auditLines,
                ]);

                return $addendum->load(['lines', 'createdBy:id,name']);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->existing($order, $operationId, $operationHash);
            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function localWarehouse(SalesOrder $order): Warehouse
    {
        $warehouse = $order->posWarehouse;
        if (! $warehouse || $warehouse->organization_id !== $order->organization_id || $warehouse->status !== WarehouseStatus::Active) {
            throw ValidationException::withMessages(['order' => 'L’entrepôt POS local actif est introuvable.']);
        }

        return $warehouse;
    }

    private function existing(SalesOrder $order, string $operationId, string $operationHash): ?SalesOrderAddendum
    {
        $existing = SalesOrderAddendum::query()
            ->where('organization_id', $order->organization_id)
            ->where('sales_order_id', $order->getKey())
            ->where('client_operation_id', $operationId)
            ->with(['lines', 'createdBy:id,name'])
            ->first();
        if ($existing && ! hash_equals($existing->operation_hash, $operationHash)) {
            throw ValidationException::withMessages([
                'client_operation_id' => 'Cet identifiant d’opération a déjà été utilisé avec un autre complément.',
            ]);
        }

        return $existing;
    }

    /** @param list<array{product_variant_id: int, quantity: string}> $items */
    private function operationHash(array $items): string
    {
        return hash('sha256', json_encode(array_map(fn (array $item) => [
            'product_variant_id' => (int) $item['product_variant_id'],
            'quantity' => Decimal::normalize($item['quantity'], 'quantity'),
        ], array_values($items)), JSON_THROW_ON_ERROR));
    }
}
