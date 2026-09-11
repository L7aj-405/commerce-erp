<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\AuthorizesQuotationAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Enums\QuotationLineType;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderSource;
use App\Enums\WarehouseStatus;
use App\Models\InventoryBalance;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Transformer un devis en commande.
 *
 * Produces a DRAFT SalesOrder (never confirmed here) whose lines mirror the
 * Devis, then links the two and moves the Devis to CONVERTED. Inventory is NOT
 * touched here — reservations happen later at the existing
 * ConfirmSalesOrderAction, exactly like any other order.
 *
 * Idempotent: a Devis that already carries `converted_sales_order_id` returns
 * that same order instead of creating a second one (double-click / retry safe).
 *
 * @phpstan-type ConversionResult array{order: SalesOrder, stock_warnings: list<array<string,string>>, reused: bool}
 */
class ConvertQuotationToSalesOrderAction
{
    use AuthorizesQuotationAction;

    public function __construct(
        private readonly CreateSalesOrderAction $createOrder,
        private readonly SaveSalesOrderLineAction $saveLine,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{warehouse_id?: int|null}  $options
     * @return array{order: SalesOrder, stock_warnings: list<array<string,string>>, reused: bool}
     */
    public function execute(User $actor, Quotation $quotation, array $options = []): array
    {
        $this->authorizeQuotation($actor, $quotation, 'quotations.convert');

        return DB::transaction(function () use ($actor, $quotation, $options) {
            $quotation = Quotation::query()
                ->where('organization_id', $quotation->organization_id)
                ->where('store_id', $quotation->store_id)
                ->whereKey($quotation->getKey())
                ->lockForUpdate()
                ->with(['lines.nonStockItem', 'organization', 'store'])
                ->firstOrFail();

            // Idempotency guard.
            if ($quotation->converted_sales_order_id) {
                $existing = SalesOrder::query()
                    ->where('organization_id', $quotation->organization_id)
                    ->whereKey($quotation->converted_sales_order_id)
                    ->first();
                if ($existing) {
                    return ['order' => $existing, 'stock_warnings' => [], 'reused' => true];
                }
            }

            if (! in_array($quotation->status, [QuotationStatus::Issued, QuotationStatus::Accepted, QuotationStatus::Expired], true)) {
                throw ValidationException::withMessages(['quotation' => 'Seul un devis émis ou accepté peut être transformé en commande.']);
            }
            if ($quotation->lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Un devis vide ne peut pas être transformé.']);
            }

            $hasCatalog = $quotation->lines->contains(fn ($line) => $line->line_type === QuotationLineType::Catalog);
            $warehouse = $hasCatalog ? $this->warehouse($quotation, $options['warehouse_id'] ?? null) : null;

            $order = $this->createOrder->execute(
                $actor,
                $quotation->organization,
                $quotation->store,
                [
                    'customer_id' => $quotation->customer_id,
                    'sale_date' => now()->toDateString(),
                    'currency_code' => $quotation->currency_code,
                    'notes' => $quotation->notes,
                ],
                SalesOrderSource::Manual,
                (string) Str::uuid(),
            );

            $warnings = [];
            foreach ($quotation->lines as $line) {
                if ($line->line_type === QuotationLineType::Catalog) {
                    $this->saveLine->execute($actor, $order, [
                        'line_type' => 'catalog',
                        'product_variant_id' => $line->product_variant_id,
                        'warehouse_id' => $warehouse->getKey(),
                        'quantity' => (string) $line->quantity,
                        'unit_price_excl_tax' => (string) $line->unit_price_excl_tax,
                        'discount_type' => $line->discount_type->value,
                        'discount_value' => (string) $line->discount_value,
                    ]);

                    $warning = $this->stockWarning($quotation, $line);
                    if ($warning !== null) {
                        $warnings[] = $warning;
                    }
                } else {
                    $this->saveLine->execute($actor, $order, [
                        'line_type' => 'custom',
                        'description' => $line->description,
                        'reference' => $line->reference,
                        'unit_label' => $line->unit_label,
                        'quantity' => (string) $line->quantity,
                        'unit_price_excl_tax' => (string) $line->unit_price_excl_tax,
                        'tax_rate_id' => $line->nonStockItem?->tax_rate_id,
                        'discount_type' => $line->discount_type->value,
                        'discount_value' => (string) $line->discount_value,
                    ]);
                }
            }

            $quotation->status = QuotationStatus::Converted;
            $quotation->converted_at = now();
            $quotation->converted_sales_order_id = $order->getKey();
            $quotation->save();

            $this->audit->record('quotation.converted', $actor, $quotation->organization, $quotation->store, $quotation, newValues: [
                'quotation_number' => $quotation->quotation_number,
                'sales_order_id' => $order->getKey(),
                'sales_order_number' => $order->order_number,
                'stock_warnings' => count($warnings),
            ]);

            return ['order' => $order->fresh(['lines']), 'stock_warnings' => $warnings, 'reused' => false];
        });
    }

    private function warehouse(Quotation $quotation, mixed $warehouseId): Warehouse
    {
        $query = Warehouse::query()
            ->where('organization_id', $quotation->organization_id)
            ->where('status', WarehouseStatus::Active->value);

        $warehouse = $warehouseId
            ? (clone $query)->whereKey($warehouseId)->first()
            : (clone $query)->orderBy('name')->first();

        if (! $warehouse) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'Sélectionnez un entrepôt actif pour les lignes catalogue avant de transformer le devis.',
            ]);
        }

        return $warehouse;
    }

    /** @return array<string, string>|null */
    private function stockWarning(Quotation $quotation, \App\Models\QuotationLine $line): ?array
    {
        $available = InventoryBalance::query()
            ->where('organization_id', $quotation->organization_id)
            ->where('product_variant_id', $line->product_variant_id)
            ->whereHas('warehouse', fn ($q) => $q->where('status', WarehouseStatus::Active->value))
            ->get()
            ->reduce(fn (string $carry, InventoryBalance $b) => Decimal::add($carry, $b->available), '0.0000');

        if (Decimal::compare((string) $line->quantity, $available) > 0) {
            return [
                'product_name' => (string) ($line->product_name ?? $line->description),
                'requested' => (string) $line->quantity,
                'available' => $available,
            ];
        }

        return null;
    }
}
