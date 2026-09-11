<?php

namespace App\Actions\Pos;

use App\Actions\Payments\RecordSalesOrderPaymentsAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Enums\PaymentMethod;
use App\Enums\SalesOrderDiscountType;
use App\Enums\SalesOrderLineType;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesOrderProcurement;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PosDraftCheckoutCalculator;
use App\Services\PosStockAllocator;
use App\Services\SalesLineCalculator;
use App\Services\SalesOrderPaymentCalculator;
use App\Services\SalesOrderTotalsCalculator;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePosSaleAction
{
    /**
     * Tax treatment of the POS delivery fee.
     *
     * The fee is captured as a single tax-exclusive amount and added as a custom
     * order line taxed at this rate. It is currently 0% — the delivery fee is
     * entered and charged as-is, VAT-free (HT === TTC). Set a `tax_rate_id`-backed
     * rate here (and adjust `applyShippingLine`) to make delivery VAT-able.
     */
    private const SHIPPING_TAX_RATE_ID = null;

    public function __construct(
        private readonly CreateSalesOrderAction $createOrder,
        private readonly SaveSalesOrderLineAction $saveLine,
        private readonly ConfirmSalesOrderAction $confirmOrder,
        private readonly RecordSalesOrderPaymentsAction $recordPayments,
        private readonly SalesOrderPaymentCalculator $paymentCalculator,
        private readonly FulfillSalesOrderAction $fulfillOrder,
        private readonly SalesLineCalculator $lineCalculator,
        private readonly SalesOrderTotalsCalculator $totals,
        private readonly PosDraftCheckoutCalculator $posSummary,
        private readonly PosStockAllocator $stockAllocator,
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

                $this->assertProcurementDeficitCovered($draft);

                $checkoutHash = $this->draftCheckoutHash($draft, $payments);
                $existing = $this->findExisting($organization, $store, $data['client_operation_id'], true);
                if ($existing) {
                    return $this->completed($existing, $checkoutHash);
                }

                $this->applyGlobalDiscount($draft);
                $this->applyShippingLine($actor, $draft);
                $draft->client_operation_id = $data['client_operation_id'];
                $draft->pos_checkout_hash = $checkoutHash;
                $draft->save();

                $confirmed = $this->confirmOrder->execute($actor, $draft->fresh());
                $requiresReplenishment = $this->requiresReplenishment($confirmed, (int) $draft->pos_warehouse_id);
                // A confirmed order still waiting on supplier-procured goods is a
                // special order: it is not fulfilled on the spot and the customer
                // is free to pay now, partly, or later (§15).
                $awaitingProcurement = $confirmed->awaitingSupplierProcurement();
                $remaining = $this->paymentCalculator->remainingAmount($confirmed);
                $paymentTotal = $this->paymentTotal($payments);
                $fulfillmentMode = $draft->pos_fulfillment_mode ?? 'pickup';

                if (Decimal::compare($paymentTotal, $remaining) > 0) {
                    throw ValidationException::withMessages([
                        'payments' => "Le total des paiements dépasse le montant dû autorisé de {$remaining}.",
                    ]);
                }

                if ($fulfillmentMode !== 'delivery' && ! $awaitingProcurement && Decimal::compare($paymentTotal, $remaining) !== 0) {
                    throw ValidationException::withMessages([
                        'payments' => 'Le retrait immédiat exige un paiement intégral avant la remise au client.',
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
                $order->save();

                foreach ($lines as $line) {
                    if ($line['line_type'] === 'catalog') {
                        $line['warehouse_id'] = $data['warehouse_id'];
                    }

                    $this->saveLine->execute($actor, $order, $line);
                }

                $confirmed = $this->confirmOrder->execute($actor, $order);
                $requiresReplenishment = $this->requiresReplenishment($confirmed, (int) $data['warehouse_id']);
                $awaitingProcurement = $confirmed->awaitingSupplierProcurement();
                $remaining = $this->paymentCalculator->remainingAmount($confirmed);
                $paymentTotal = $this->paymentTotal($payments);
                $fulfillmentMode = $data['fulfillment_mode'] ?? 'pickup';

                if (Decimal::compare($paymentTotal, $remaining) > 0) {
                    throw ValidationException::withMessages([
                        'payments' => "Le total des paiements dépasse le montant dû autorisé de {$remaining}.",
                    ]);
                }

                if ($fulfillmentMode !== 'delivery' && ! $awaitingProcurement && Decimal::compare($paymentTotal, $remaining) !== 0) {
                    throw ValidationException::withMessages([
                        'payments' => 'Le retrait immédiat exige un paiement intégral avant la remise au client.',
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

    private function applyGlobalDiscount(SalesOrder $order): void
    {
        $distribution = $this->posSummary->distributedLineDiscounts($order);
        if ($distribution === []) {
            return;
        }

        foreach ($distribution as $item) {
            /** @var SalesOrder $order */
            /** @var SalesOrderLine $line */
            $line = $item['line'];
            if ($line->discount_type->value === $item['discount_type'] && Decimal::compare($line->discount_value, $item['discount_value']) === 0) {
                continue;
            }

            $calculated = $this->lineCalculator->calculate(
                $line->quantity,
                $line->unit_price_excl_tax,
                $line->tax_rate,
                SalesOrderDiscountType::Fixed,
                $item['discount_value'],
            );

            $line->discount_type = SalesOrderDiscountType::Fixed;
            foreach ($calculated as $field => $value) {
                $line->{$field} = $value;
            }
            $line->save();
        }

        $this->totals->recalculate($order->fresh());
    }

    private function applyShippingLine(User $actor, SalesOrder $order): void
    {
        $shipping = Decimal::normalize((string) ($order->pos_shipping_fee ?? '0'));
        if (Decimal::compare($shipping, '0.0000') <= 0) {
            return;
        }

        $existing = $order->lines()->where('line_type', 'custom')->where('reference', '__POS_SHIPPING__')->first();

        $this->saveLine->execute($actor, $order, [
            'line_type' => 'custom',
            'description' => 'Frais de livraison',
            'reference' => '__POS_SHIPPING__',
            'unit_label' => 'service',
            'quantity' => '1.0000',
            'unit_price_excl_tax' => $shipping,
            'tax_rate_id' => self::SHIPPING_TAX_RATE_ID,
            'discount_type' => SalesOrderDiscountType::None->value,
            'discount_value' => '0.0000',
        ], $existing);
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

    /**
     * A POS special order may only be finalised once every catalogue line is
     * covered by `company stock + CONFIRMED supplier procurement`. An uncovered
     * or still-pending deficit is not an instant sale — the cashier must raise /
     * confirm a supplier procurement first (§11, §12).
     */
    private function assertProcurementDeficitCovered(SalesOrder $draft): void
    {
        foreach ($draft->lines as $line) {
            if ($line->line_type !== SalesOrderLineType::Catalog || $line->product_variant_id === null || ! $line->productVariant) {
                continue;
            }
            $companyAvailable = $this->stockAllocator->availableForLine($draft, $line->productVariant, $line);
            $confirmedProcurement = $line->procurements
                ->filter(fn (SalesOrderProcurement $p) => in_array(
                    $p->status->value,
                    ['supplier_confirmed', 'ordered', 'received', 'completed'],
                    true,
                ))
                ->reduce(fn (string $total, SalesOrderProcurement $p) => Decimal::add($total, $p->quantity), '0.0000');

            if (Decimal::compare(Decimal::add($companyAvailable, $confirmedProcurement), $line->quantity) < 0) {
                throw ValidationException::withMessages([
                    'order_id' => 'Certains articles sont en rupture et ne sont pas couverts par un approvisionnement fournisseur confirmé. Ouvrez « Approvisionner auprès d’un fournisseur », faites confirmer la disponibilité, puis finalisez la vente.',
                ]);
            }
        }
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
