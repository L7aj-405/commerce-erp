<?php

namespace App\Actions\Pos;

use App\Actions\Payments\RecordSalesOrderPaymentsAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Enums\PaymentMethod;
use App\Enums\PosFulfillmentOrigin;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Pos\PosCartCommercialTerms;
use App\Services\Pos\PosCartStockGuard;
use App\Services\PosDraftCheckoutCalculator;
use App\Services\SalesOrderPaymentCalculator;
use App\Services\SalespersonEligibilityService;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePosSaleAction
{
    public function __construct(
        private readonly CreateSalesOrderAction $createOrder,
        private readonly SaveSalesOrderLineAction $saveLine,
        private readonly ConfirmSalesOrderAction $confirmOrder,
        private readonly RecordSalesOrderPaymentsAction $recordPayments,
        private readonly SalesOrderPaymentCalculator $paymentCalculator,
        private readonly FulfillSalesOrderAction $fulfillOrder,
        private readonly PosCartCommercialTerms $commercialTerms,
        private readonly PosDraftCheckoutCalculator $posSummary,
        private readonly PosCartStockGuard $stockGuard,
        private readonly SalespersonEligibilityService $salespersons,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Organization $organization, Store $store, array $data): SalesOrder
    {
        $this->authorizeCheckout($actor, $organization, $store);
        $payments = $this->normalizedPayments($data['payments']);

        return isset($data['order_id'])
            ? $this->completeDraft($actor, $organization, $store, $data, $payments)
            : $this->completeLegacyCheckout($actor, $organization, $store, $data, $payments);
    }

    /** @param list<array{method: string, financial_account_id: int, amount: string, cash_received: ?string, reference: ?string}> $payments */
    private function completeDraft(User $actor, Organization $organization, Store $store, array $data, array $payments): SalesOrder
    {
        try {
            return DB::transaction(function () use ($actor, $organization, $store, $data, $payments) {
                $draft = SalesOrder::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('store_id', $store->getKey())
                    ->where('source', SalesOrderSource::Pos->value)
                    ->whereKey($data['order_id'])
                    ->lockForUpdate()
                    ->with(['lines.allocations', 'lines.productVariant', 'lines.procurements', 'store', 'customer', 'organization'])
                    ->firstOrFail();

                if ($draft->status !== SalesOrderStatus::Draft) {
                    throw ValidationException::withMessages([
                        'order_id' => 'Cette vente POS ne peut plus être finalisée.',
                    ]);
                }

                if ($draft->lines->isEmpty()) {
                    throw ValidationException::withMessages([
                        'order_id' => 'Ajoutez au moins un produit avant de finaliser la vente.',
                    ]);
                }

                if ($draft->pos_converted_at !== null) {
                    throw ValidationException::withMessages([
                        'order_id' => 'Ce panier a été transformé en commande client : poursuivez depuis la commande.',
                    ]);
                }

                // Never trust the cart's old stock snapshot: lock the balances,
                // re-check company coverage (PosStockConflictException lists every
                // short article and rolls the whole checkout back) and re-plan
                // stale warehouse allocations before anything is written.
                $this->stockGuard->assertCovered($draft);

                // Fulfillment origin: an explicit choice in this checkout wins,
                // then the one saved on the cart, then the delivery method's
                // default. Unsupported combinations are refused.
                $draft->pos_fulfillment_origin = $this->resolveOrigin(
                    $draft->pos_fulfillment_mode, $data['fulfillment_origin'] ?? null, $draft->pos_fulfillment_origin,
                );

                $checkoutHash = $this->draftCheckoutHash($draft, $payments);
                $existing = $this->findExisting($organization, $store, $data['client_operation_id'], true);
                if ($existing) {
                    return $this->completed($existing, $checkoutHash);
                }

                $this->commercialTerms->apply($actor, $draft);
                // The employee completing checkout owns the POS sale. A held
                // cart cannot silently retain another user's attribution.
                $salesperson = $this->salespersons->resolve($organization, $actor->getKey());
                $draft->salesperson_id = $salesperson->getKey();
                $draft->salesperson_name_snapshot = $salesperson->name;
                $draft->client_operation_id = $data['client_operation_id'];
                $draft->pos_checkout_hash = $checkoutHash;
                $draft->save();

                $confirmed = $this->confirmOrder->execute($actor, $draft->fresh());
                $this->recordOrigin($actor, $confirmed, isset($data['fulfillment_origin']));
                $requiresReplenishment = $this->requiresReplenishment($confirmed, (int) $draft->pos_warehouse_id);
                // A confirmed order still waiting on supplier-procured goods is a
                // special order: it is not fulfilled on the spot and the customer
                // is free to pay now, partly, or later (§15).
                $awaitingProcurement = $confirmed->awaitingSupplierProcurement();
                $remaining = $this->paymentCalculator->remainingAmount($confirmed);
                $paymentTotal = $this->paymentTotal($payments);
                $fulfillmentMode = $draft->pos_fulfillment_mode ?? 'pickup';

                // Full, partial, or zero payment ("payment later") are all
                // legitimate POS settlements — the cashier's settlement choice
                // decides this, not the fulfillment mode. The only hard rule is
                // that a payment can never exceed the amount actually due.
                if (Decimal::compare($paymentTotal, $remaining) > 0) {
                    throw ValidationException::withMessages([
                        'payments' => "Le total des paiements dépasse le montant dû autorisé de {$remaining}.",
                    ]);
                }

                $this->recordPayments->execute(
                    $actor,
                    $confirmed,
                    array_map(fn (array $payment) => [
                        'method' => $payment['method'],
                        'financial_account_id' => $payment['financial_account_id'],
                        'amount' => $payment['amount'],
                        'payment_date' => now()->toDateString(),
                        'reference' => $payment['reference'],
                    ], $payments),
                    $data['client_operation_id'],
                );

                $completed = $draft->fresh(['store:id,name,code', 'customer:id,display_name', 'paymentAllocations.payment.financialAccount']);

                if ($awaitingProcurement) {
                    $this->audit->record('sales_order.pos_awaiting_supplier_procurement', $actor, $draft->organization, $draft->store, $draft, newValues: [
                        'order_number' => $draft->order_number,
                        'pos_warehouse_id' => $draft->pos_warehouse_id,
                    ]);
                } elseif ($fulfillmentMode !== 'delivery' && ! $requiresReplenishment) {
                    $completed = $this->fulfillOrder->execute($actor, $confirmed->fresh())
                        ->load(['store:id,name,code', 'customer:id,display_name', 'paymentAllocations.payment.financialAccount']);
                } elseif ($fulfillmentMode !== 'delivery') {
                    $this->audit->record('sales_order.pos_replenishment_required', $actor, $draft->organization, $draft->store, $draft, newValues: [
                        'order_number' => $draft->order_number,
                        'pos_warehouse_id' => $draft->pos_warehouse_id,
                    ]);
                } else {
                    $this->audit->record('sales_order.pos_delivery_confirmed', $actor, $draft->organization, $draft->store, $draft, newValues: [
                        'order_number' => $draft->order_number,
                        'pos_shipping_fee' => $draft->pos_shipping_fee,
                        'delivery_phone' => $draft->pos_delivery_phone,
                    ]);
                }

                return $completed;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findExisting($organization, $store, $data['client_operation_id']);
            if (! $existing) {
                throw $exception;
            }

            return $this->completed($existing, $this->draftCheckoutHash($existing, $payments));
        }
    }

    /** @param list<array{method: string, financial_account_id: int, amount: string, cash_received: ?string, reference: ?string}> $payments */
    private function completeLegacyCheckout(User $actor, Organization $organization, Store $store, array $data, array $payments): SalesOrder
    {
        $lines = $this->normalizedLines($data['lines']);
        $checkoutHash = $this->legacyCheckoutHash($data, $lines, $payments);

        try {
            return DB::transaction(function () use ($actor, $organization, $store, $data, $lines, $payments, $checkoutHash) {
                $existing = $this->findExisting($organization, $store, $data['client_operation_id'], true);

                if ($existing) {
                    return $this->completed($existing, $checkoutHash);
                }

                $order = $this->createOrder->execute(
                    $actor,
                    $organization,
                    $store,
                    [
                        'customer_id' => $data['customer_id'] ?? null,
                        'sale_date' => now()->toDateString(),
                        'currency_code' => config('platform.currency_code', 'MAD'),
                        'notes' => null,
                    ],
                    SalesOrderSource::Pos,
                    $data['client_operation_id'],
                );
                $order->pos_checkout_hash = $checkoutHash;
                $order->pos_warehouse_id = (int) $data['warehouse_id'];
                $order->pos_fulfillment_mode = $data['fulfillment_mode'] ?? 'pickup';
                $order->pos_fulfillment_origin = $this->resolveOrigin($order->pos_fulfillment_mode, $data['fulfillment_origin'] ?? null, null);
                $order->save();

                foreach ($lines as $line) {
                    if ($line['line_type'] === 'catalog') {
                        $line['warehouse_id'] = $data['warehouse_id'];
                    }

                    $this->saveLine->execute($actor, $order, $line);
                }

                $confirmed = $this->confirmOrder->execute($actor, $order);
                $this->recordOrigin($actor, $confirmed, isset($data['fulfillment_origin']));
                $requiresReplenishment = $this->requiresReplenishment($confirmed, (int) $data['warehouse_id']);
                $awaitingProcurement = $confirmed->awaitingSupplierProcurement();
                $remaining = $this->paymentCalculator->remainingAmount($confirmed);
                $paymentTotal = $this->paymentTotal($payments);
                $fulfillmentMode = $data['fulfillment_mode'] ?? 'pickup';

                // Full, partial, or zero payment ("payment later") are all
                // legitimate POS settlements — the cashier's settlement choice
                // decides this, not the fulfillment mode. The only hard rule is
                // that a payment can never exceed the amount actually due.
                if (Decimal::compare($paymentTotal, $remaining) > 0) {
                    throw ValidationException::withMessages([
                        'payments' => "Le total des paiements dépasse le montant dû autorisé de {$remaining}.",
                    ]);
                }

                $this->recordPayments->execute(
                    $actor,
                    $confirmed,
                    array_map(fn (array $payment) => [
                        'method' => $payment['method'],
                        'financial_account_id' => $payment['financial_account_id'],
                        'amount' => $payment['amount'],
                        'payment_date' => now()->toDateString(),
                        'reference' => $payment['reference'],
                    ], $payments),
                    $data['client_operation_id'],
                );

                $completed = $confirmed->fresh(['store:id,name,code', 'customer:id,display_name', 'paymentAllocations.payment.financialAccount']);

                if ($awaitingProcurement) {
                    $this->audit->record('sales_order.pos_awaiting_supplier_procurement', $actor, $order->organization, $order->store, $order, newValues: [
                        'order_number' => $order->order_number,
                        'pos_warehouse_id' => $order->pos_warehouse_id,
                    ]);
                } elseif ($fulfillmentMode !== 'delivery' && ! $requiresReplenishment) {
                    $completed = $this->fulfillOrder->execute($actor, $confirmed->fresh())
                        ->load(['store:id,name,code', 'customer:id,display_name', 'paymentAllocations.payment.financialAccount']);
                } elseif ($fulfillmentMode !== 'delivery') {
                    $this->audit->record('sales_order.pos_replenishment_required', $actor, $order->organization, $order->store, $order, newValues: [
                        'order_number' => $order->order_number,
                        'pos_warehouse_id' => $order->pos_warehouse_id,
                    ]);
                }

                return $completed;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findExisting($organization, $store, $data['client_operation_id']);

            if (! $existing) {
                throw $exception;
            }

            return $this->completed($existing, $checkoutHash);
        }
    }

    /** @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function normalizedLines(array $lines): array
    {
        $normalized = [];

        foreach ($lines as $line) {
            if ($line['line_type'] !== 'catalog') {
                $normalized[] = $line;

                continue;
            }

            $key = implode(':', [
                $line['product_variant_id'],
                $line['unit_price_excl_tax'] ?? 'default',
                $line['discount_type'] ?? 'none',
                $line['discount_value'] ?? '0',
            ]);

            if (isset($normalized[$key])) {
                $normalized[$key]['quantity'] = Decimal::add(
                    (string) $normalized[$key]['quantity'],
                    (string) $line['quantity'],
                );

                continue;
            }

            $normalized[$key] = $line;
        }

        return array_values($normalized);
    }

    private function findExisting(Organization $organization, Store $store, string $operationId, bool $lock = false): ?SalesOrder
    {
        return SalesOrder::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey())
            ->where('client_operation_id', $operationId)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    private function completed(SalesOrder $order, string $checkoutHash): SalesOrder
    {
        if (! $order->pos_checkout_hash || ! hash_equals($order->pos_checkout_hash, $checkoutHash)) {
            throw ValidationException::withMessages([
                'client_operation_id' => 'This POS operation identifier was already used with different checkout data.',
            ]);
        }

        if ($order->source !== SalesOrderSource::Pos || $order->status !== SalesOrderStatus::Confirmed) {
            throw ValidationException::withMessages([
                'client_operation_id' => 'This POS operation exists but is not a completed POS checkout.',
            ]);
        }

        return $order->load(['store:id,name,code', 'customer:id,display_name', 'paymentAllocations.payment.financialAccount']);
    }

    /** @param list<array<string, mixed>> $payments
     * @return list<array{method: string, financial_account_id: int, amount: string, cash_received: ?string, reference: ?string}>
     */
    private function normalizedPayments(array $payments): array
    {
        return array_map(function (array $payment) {
            $method = PaymentMethod::from($payment['method']);
            $amount = Decimal::positive($payment['amount'], 'payments.amount');
            if ($method === PaymentMethod::Cash && empty($payment['cash_received'])) {
                throw ValidationException::withMessages([
                    'payments' => 'Cash received is required for a Cash Payment.',
                ]);
            }
            $cashReceived = $method === PaymentMethod::Cash
                ? Decimal::positive($payment['cash_received'], 'payments.cash_received')
                : null;
            if ($cashReceived !== null && Decimal::compare($cashReceived, $amount) < 0) {
                throw ValidationException::withMessages([
                    'payments' => 'Cash received must be greater than or equal to the Cash Payment amount.',
                ]);
            }

            return [
                'method' => $method->value,
                'financial_account_id' => (int) $payment['financial_account_id'],
                'amount' => $amount,
                'cash_received' => $cashReceived,
                'reference' => $payment['reference'] ?? null,
            ];
        }, array_values($payments));
    }

    /** @param list<array<string, mixed>> $payments */
    private function draftCheckoutHash(SalesOrder $order, array $payments): string
    {
        $summary = $this->posSummary->summary($order);
        $lines = $order->lines()->orderBy('position')->get()->map(fn ($line) => [
            'id' => $line->getKey(),
            'product_variant_id' => $line->product_variant_id,
            'description' => $line->product_name,
            'quantity' => $line->quantity,
            'unit_price_excl_tax' => $line->unit_price_excl_tax,
            'discount_type' => $line->discount_type->value,
            'discount_value' => $line->discount_value,
        ])->all();

        return hash('sha256', json_encode([
            'order_id' => $order->getKey(),
            'customer_id' => $order->customer_id,
            'fulfillment_mode' => $order->pos_fulfillment_mode ?? 'pickup',
            // Absent for orders checked out before the field existed (NULL), so
            // a late retry of such a checkout still matches its stored hash.
            ...($order->pos_fulfillment_origin !== null ? ['fulfillment_origin' => $order->pos_fulfillment_origin->value] : []),
            'shipping_fee' => $summary['shipping_fee'],
            'global_discount_type' => $summary['global_discount_type'],
            'global_discount_value' => $summary['global_discount_value'],
            'lines' => $lines,
            'payments' => $payments,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<array<string, mixed>> $lines
     * @param  list<array<string, mixed>>  $payments
     */
    private function legacyCheckoutHash(array $data, array $lines, array $payments): string
    {
        $canonicalLines = array_map(fn (array $line) => [
            'line_type' => $line['line_type'],
            'product_variant_id' => isset($line['product_variant_id']) ? (int) $line['product_variant_id'] : null,
            'description' => $line['description'] ?? null,
            'reference' => $line['reference'] ?? null,
            'unit_label' => $line['unit_label'] ?? null,
            'quantity' => Decimal::normalize($line['quantity']),
            'unit_price_excl_tax' => isset($line['unit_price_excl_tax']) ? Decimal::normalize($line['unit_price_excl_tax']) : null,
            'tax_rate_id' => isset($line['tax_rate_id']) ? (int) $line['tax_rate_id'] : null,
            'discount_type' => $line['discount_type'] ?? 'none',
            'discount_value' => Decimal::normalize($line['discount_value'] ?? '0'),
        ], $lines);

        return hash('sha256', json_encode([
            'warehouse_id' => (int) $data['warehouse_id'],
            'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            'fulfillment_mode' => $data['fulfillment_mode'] ?? 'pickup',
            // Only when sent explicitly, so older clients' retries keep their hash.
            ...(isset($data['fulfillment_origin']) ? ['fulfillment_origin' => $data['fulfillment_origin']] : []),
            'lines' => $canonicalLines,
            'payments' => $payments,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<array{method: string, financial_account_id: int, amount: string, cash_received: ?string, reference: ?string}> $payments */
    private function paymentTotal(array $payments): string
    {
        $total = '0.0000';

        foreach ($payments as $payment) {
            $total = Decimal::add($total, $payment['amount']);
        }

        return $total;
    }

    private function resolveOrigin(?string $mode, ?string $requested, ?PosFulfillmentOrigin $stored): PosFulfillmentOrigin
    {
        $origin = $requested !== null ? PosFulfillmentOrigin::from($requested) : ($stored ?? PosFulfillmentOrigin::defaultFor($mode));
        PosFulfillmentOrigin::assertSupported($mode, $origin);

        return $origin;
    }

    /** The origin decided at checkout, in the order's audit trail. */
    private function recordOrigin(User $actor, SalesOrder $order, bool $explicit): void
    {
        $this->audit->record('sales_order.pos_fulfillment_origin_selected', $actor, $order->organization, $order->store, $order, newValues: [
            'order_number' => $order->order_number,
            'fulfillment_mode' => $order->pos_fulfillment_mode ?? 'pickup',
            'fulfillment_origin' => $order->pos_fulfillment_origin?->value,
            'explicit' => $explicit,
            'pos_warehouse_id' => $order->pos_warehouse_id,
        ]);
    }

    private function requiresReplenishment(SalesOrder $order, int $posWarehouseId): bool
    {
        return $order->lines()
            ->whereHas('allocations', fn ($query) => $query->where('warehouse_id', '!=', $posWarehouseId))
            ->exists();
    }

    private function authorizeCheckout(User $actor, Organization $organization, Store $store): void
    {
        $permissions = [
            'pos.access', 'sales_orders.create', 'sales_orders.update', 'sales_orders.confirm',
            'sales_orders.fulfill', 'payments.create',
        ];
        abort_unless(
            $actor->active_organization_id === $organization->getKey()
            && $actor->active_store_id === $store->getKey()
            && $organization->status === 'active'
            && $store->organization_id === $organization->getKey()
            && $store->status === 'active'
            && $store->memberships()->where('user_id', $actor->getKey())->exists()
            && collect($permissions)->every(fn (string $permission) => $actor->hasPermission($organization, $permission)),
            403,
        );
    }
}
