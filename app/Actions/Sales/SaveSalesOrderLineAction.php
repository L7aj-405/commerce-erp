<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Enums\CatalogStatus;
use App\Enums\SalesOrderDiscountType;
use App\Enums\SalesOrderLineType;
use App\Enums\SalesOrderStatus;
use App\Enums\WarehouseStatus;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderLine;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\SalesLineCalculator;
use App\Services\SalesOrderTotalsCalculator;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveSalesOrderLineAction
{
    use AuthorizesSalesAction;

    public function __construct(private readonly SalesLineCalculator $calculator, private readonly SalesOrderTotalsCalculator $totals, private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, SalesOrder $order, array $data, ?SalesOrderLine $line = null): SalesOrderLine
    {
        $this->authorizeOrder($actor, $order, 'sales_orders.update');

        return DB::transaction(function () use ($actor, $order, $data, $line) {
            $order = SalesOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($order->status !== SalesOrderStatus::Draft) {
                throw ValidationException::withMessages(['order' => 'Lines cannot be changed after confirmation.']);
            }
            if ($line) {
                $line = SalesOrderLine::query()->where('organization_id', $order->organization_id)
                    ->where('sales_order_id', $order->getKey())->whereKey($line->getKey())->firstOrFail();
            }
            $type = SalesOrderLineType::from($data['line_type']);
            $discountType = SalesOrderDiscountType::from($data['discount_type'] ?? SalesOrderDiscountType::None->value);
            if ($discountType !== SalesOrderDiscountType::None && ! $actor->hasPermission($order->organization_id, 'sales_orders.apply_discount')) {
                abort(403);
            }

            [$snapshot, $defaultPrice, $taxRate, $taxName, $warehouse] = $type === SalesOrderLineType::Catalog
                ? $this->catalog($order, $data)
                : $this->custom($order, $data);
            $unitPrice = $data['unit_price_excl_tax'] ?? $defaultPrice;
            if ($type === SalesOrderLineType::Catalog && Decimal::compare($unitPrice, $defaultPrice) !== 0) {
                abort_unless($actor->hasPermission($order->organization_id, 'sales_orders.override_price'), 403);
                $this->audit->record('sales_order.price_overridden', $actor, $order->organization, $order->store, $order, newValues: [
                    'order_number' => $order->order_number, 'product_variant_id' => $snapshot['product_variant_id'],
                    'default_price' => $defaultPrice, 'override_price' => Decimal::normalize($unitPrice),
                ]);
            }
            $calculated = $this->calculator->calculate($data['quantity'], $unitPrice, $taxRate, $discountType, $data['discount_value'] ?? '0');
            if ($discountType !== SalesOrderDiscountType::None) {
                $this->audit->record('sales_order.discount_applied', $actor, $order->organization, $order->store, $order, newValues: [
                    'order_number' => $order->order_number, 'discount_type' => $discountType->value,
                    'discount_value' => $calculated['discount_value'], 'discount_amount' => $calculated['discount_amount'],
                ]);
            }

            $line ??= new SalesOrderLine;
            $line->organization_id = $order->organization_id;
            $line->sales_order_id = $order->getKey();
            $line->line_type = $type;
            $line->position ??= ((int) $order->lines()->max('position')) + 1;
            foreach ($snapshot as $field => $value) {
                $line->{$field} = $value;
            }
            $line->tax_name = $taxName;
            $line->discount_type = $discountType;
            foreach ($calculated as $field => $value) {
                $line->{$field} = $value;
            }
            $line->save();
            $line->allocations()->delete();
            if ($warehouse) {
                $allocation = new SalesOrderInventoryAllocation;
                $allocation->organization_id = $order->organization_id;
                $allocation->sales_order_line_id = $line->getKey();
                $allocation->warehouse_id = $warehouse->getKey();
                $allocation->quantity = $calculated['quantity'];
                $allocation->save();
            }
            $this->totals->recalculate($order);

            return $line->load('allocations.warehouse');
        });
    }

    /** @return array{array<string, mixed>, string, string, ?string, Warehouse} */
    private function catalog(SalesOrder $order, array $data): array
    {
        $variant = ProductVariant::query()->where('organization_id', $order->organization_id)->whereKey($data['product_variant_id'])
            ->with(['product.defaultUnit', 'taxRate'])->firstOrFail();
        if ($variant->status !== CatalogStatus::Active || $variant->product->status !== CatalogStatus::Active) {
            throw ValidationException::withMessages(['product_variant_id' => 'Only active catalog variants may be added.']);
        }
        $warehouse = Warehouse::query()->where('organization_id', $order->organization_id)->whereKey($data['warehouse_id'])->firstOrFail();
        if ($warehouse->status !== WarehouseStatus::Active) {
            throw ValidationException::withMessages(['warehouse_id' => 'Only an active warehouse may be allocated.']);
        }

        return [[
            'product_variant_id' => $variant->getKey(), 'product_name' => $variant->product->name,
            'variant_name' => $variant->label, 'sku' => $variant->sku, 'reference' => $variant->reference,
            'unit_label' => $variant->product->defaultUnit?->symbol ?? $variant->product->defaultUnit?->name,
        ], $variant->default_sale_price, $variant->taxRate?->rate ?? '0.0000', $variant->taxRate?->name, $warehouse];
    }

    /** @return array{array<string, mixed>, string, string, ?string, null} */
    private function custom(SalesOrder $order, array $data): array
    {
        $tax = ! empty($data['tax_rate_id'])
            ? TaxRate::query()->where('organization_id', $order->organization_id)->whereKey($data['tax_rate_id'])->firstOrFail()
            : null;
        if ($tax && $tax->status !== CatalogStatus::Active) {
            throw ValidationException::withMessages(['tax_rate_id' => 'Only an active tax rate may be selected.']);
        }

        return [[
            'product_variant_id' => null, 'product_name' => $data['description'], 'variant_name' => null,
            'sku' => null, 'reference' => $data['reference'] ?? null, 'unit_label' => $data['unit_label'] ?? null,
        ], $data['unit_price_excl_tax'], $tax?->rate ?? '0.0000', $tax?->name, null];
    }
}
