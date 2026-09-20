<?php

namespace App\Actions\Exchanges;

use App\Actions\Returns\CreateCustomerReturnAction;
use App\Enums\CatalogStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderDiscountType;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Enums\WarehouseStatus;
use App\Models\CustomerExchange;
use App\Models\InventoryBalance;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CustomerExchangeNumberGenerator;
use App\Services\ProductPriceResolver;
use App\Services\SalesLineCalculator;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCustomerExchangeAction
{
    public function __construct(
        private readonly CreateCustomerReturnAction $returns,
        private readonly CustomerExchangeNumberGenerator $numbers,
        private readonly ProductPriceResolver $prices,
        private readonly SalesLineCalculator $lines,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<array{sales_order_line_id:int,quantity:string}> $returnedItems
     *  @param list<array{product_variant_id:int,quantity:string}> $replacementItems
     */
    public function execute(User $actor, SalesOrder $order, string $operationId, array $returnedItems, array $replacementItems, string $reason, string $disposition, bool $override = false, ?string $overrideReason = null): CustomerExchange
    {
        abort_unless($actor->active_organization_id === $order->organization_id && $actor->active_store_id === $order->store_id, 404);
        abort_unless($actor->hasPermission($order->organization_id, 'sales_exchanges.create'), 403);
        if ($replacementItems === []) throw ValidationException::withMessages(['replacement_items' => 'Sélectionnez au moins un nouvel article.']);

        $normalized = collect($replacementItems)->map(fn (array $item) => [
            'product_variant_id' => (int) $item['product_variant_id'],
            'quantity' => Decimal::positive($item['quantity'], 'replacement_items.quantity'),
        ])->values()->all();
        $hash = hash('sha256', json_encode([$returnedItems, $normalized, trim($reason), $disposition, $override, trim((string) $overrideReason)], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($actor, $order, $operationId, $returnedItems, $normalized, $reason, $disposition, $override, $overrideReason, $hash) {
            $order = SalesOrder::query()->where('organization_id', $order->organization_id)->where('store_id', $order->store_id)
                ->whereKey($order->id)->lockForUpdate()->with(['organization', 'store', 'posWarehouse'])->firstOrFail();
            $existing = CustomerExchange::query()->where('organization_id', $order->organization_id)
                ->where('sales_order_id', $order->id)->where('client_operation_id', $operationId)->first();
            if ($existing) {
                if (! hash_equals($existing->operation_hash, $hash)) {
                    throw ValidationException::withMessages(['client_operation_id' => 'Cet identifiant a déjà été utilisé pour un autre échange.']);
                }
                return $existing;
            }

            if (($order->pos_global_discount_type ?? 'none') !== 'none' && Decimal::compare($order->pos_global_discount_value ?? '0', '0') > 0) {
                throw ValidationException::withMessages(['order' => 'Une remise globale historique empêche l’échange automatique.']);
            }
            if ($order->source !== SalesOrderSource::Pos || $order->status !== SalesOrderStatus::Confirmed
                || $order->fulfillment_status !== SalesOrderFulfillmentStatus::Fulfilled || $order->pos_fulfillment_mode !== 'pickup'
                || ! $order->posWarehouse || $order->posWarehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages(['order' => 'Seule une vente POS déjà remise depuis un entrepôt local actif peut être échangée.']);
            }
            if ($order->hasActiveCorrection() || $order->invoices()->where('status', InvoiceStatus::Draft->value)->exists()
                || $order->deliveryNotes()->whereIn('status', ['draft', 'issued'])->exists()) {
                throw ValidationException::withMessages(['order' => 'Terminez les documents ou corrections en cours avant de créer l’échange.']);
            }
            if (! $order->invoices()->where('status', InvoiceStatus::Issued->value)->exists()) {
                throw ValidationException::withMessages([
                    'order' => 'Émettez d’abord la facture de la vente d’origine afin que le retour puisse produire un Avoir traçable.',
                ]);
            }

            $plannedRestock = collect();
            if ($disposition === 'restock') {
                $sourceLines = SalesOrderLine::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->id)
                    ->whereIn('id', collect($returnedItems)->pluck('sales_order_line_id'))->get(['id', 'product_variant_id'])->keyBy('id');
                foreach ($returnedItems as $item) {
                    $variantId = $sourceLines->get((int) $item['sales_order_line_id'])?->product_variant_id;
                    if ($variantId) $plannedRestock[$variantId] = Decimal::add($plannedRestock[$variantId] ?? '0.0000', $item['quantity']);
                }
            }

            $newTotal = '0.0000';
            foreach ($normalized as $item) {
                $variant = ProductVariant::query()->where('organization_id', $order->organization_id)->whereKey($item['product_variant_id'])
                    ->with(['product', 'taxRate'])->firstOrFail();
                if ($variant->status !== CatalogStatus::Active || $variant->product->status !== CatalogStatus::Active) {
                    throw ValidationException::withMessages(['replacement_items' => 'Seuls les articles actifs peuvent être échangés.']);
                }
                $price = $this->prices->resolve($variant, $order->store);
                if ($price['config_missing'] || $price['unit_price_ht'] === null) {
                    throw ValidationException::withMessages(['replacement_items' => "Le prix ou la TVA de {$variant->product->name} n’est pas configuré."]);
                }
                $calculated = $this->lines->calculate($item['quantity'], $price['unit_price_ht'], $price['tax_rate_value'], SalesOrderDiscountType::None, '0');
                $newTotal = Decimal::add($newTotal, $calculated['total_incl_tax']);

                $balance = InventoryBalance::query()->where('organization_id', $order->organization_id)
                    ->where('warehouse_id', $order->pos_warehouse_id)->where('product_variant_id', $variant->id)->first();
                // A planned restock may satisfy a same-SKU replacement, but
                // only after ReceiveCustomerReturnAction makes that stock
                // physically authoritative. Fulfilment re-locks/revalidates.
                $available = Decimal::add($balance?->available ?? '0.0000', $plannedRestock[$variant->id] ?? '0.0000');
                if (Decimal::compare($available, $item['quantity']) < 0) {
                    throw ValidationException::withMessages(['replacement_items' => "Stock local insuffisant pour {$variant->product->name}."]);
                }
            }

            $return = $this->returns->execute($actor, $order, $returnedItems, $reason, $disposition, $operationId, $override, $overrideReason);
            $exchange = new CustomerExchange;
            $exchange->organization_id = $order->organization_id;
            $exchange->store_id = $order->store_id;
            $exchange->sales_order_id = $order->id;
            $exchange->customer_return_id = $return->id;
            $exchange->exchange_number = $this->numbers->next($order->organization, now()->year);
            $exchange->client_operation_id = $operationId;
            $exchange->operation_hash = $hash;
            $exchange->status = 'awaiting_return_receipt';
            $exchange->settlement_status = 'pending';
            $exchange->replacement_items = $normalized;
            $exchange->returned_total = $return->total_incl_tax;
            $exchange->new_items_total = $newTotal;
            $exchange->difference_amount = Decimal::subtract($newTotal, $return->total_incl_tax);
            $exchange->created_by_user_id = $actor->id;
            $exchange->save();

            $this->audit->record('sales_exchange.created', $actor, $order->organization, $order->store, $exchange, newValues: [
                'exchange_number' => $exchange->exchange_number, 'sales_order_id' => $order->id,
                'customer_return_id' => $return->id, 'returned_total' => $exchange->returned_total,
                'new_items_total' => $exchange->new_items_total, 'difference_amount' => $exchange->difference_amount,
            ]);

                return $exchange->load(['customerReturn.lines', 'salesOrder']);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = CustomerExchange::query()->where('organization_id', $order->organization_id)
                ->where('sales_order_id', $order->id)->where('client_operation_id', $operationId)->first();
            if (! $existing) throw $exception;
            if (! hash_equals($existing->operation_hash, $hash)) {
                throw ValidationException::withMessages(['client_operation_id' => 'Cet identifiant a déjà été utilisé pour un autre échange.']);
            }

            return $existing->load(['customerReturn.lines', 'salesOrder']);
        }
    }
}
