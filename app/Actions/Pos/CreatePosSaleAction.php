<?php

namespace App\Actions\Pos;

use App\Actions\Payments\RecordSalesOrderPaymentsAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Enums\PaymentMethod;
use App\Enums\SalesOrderDiscountType;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PosDraftCheckoutCalculator;
use App\Services\SalesLineCalculator;
use App\Services\SalesOrderPaymentCalculator;
use App\Services\SalesOrderTotalsCalculator;
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
        private readonly SalesLineCalculator $lineCalculator,
        private readonly SalesOrderTotalsCalculator $totals,
        private readonly PosDraftCheckoutCalculator $posSummary,
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
                    ->with(['lines.allocations', 'store', 'customer', 'organization'])
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
                $remaining = $this->paymentCalculator->remainingAmount($confirmed);
                $paymentTotal = $this->paymentTotal($payments);
                $fulfillmentMode = $draft->pos_fulfillment_mode ?? 'pickup';

                if (Decimal::compare($paymentTotal, $remaining) > 0) {
                    throw ValidationException::withMessages([
                        'payments' => "Le total des paiements dépasse le montant dû autorisé de {$remaining}.",
                    ]);
                }

                if ($fulfillmentMode !== 'delivery' && Decimal::compare($paymentTotal, $remaining) !== 0) {
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

                if ($fulfillmentMode !== 'delivery') {
                    $completed = $this->fulfillOrder->execute($actor, $confirmed->fresh())
                        ->load(['store:id,name,code', 'customer:id,display_name', 'paymentAllocations.payment.financialAccount']);
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
                $order->save();

                foreach ($lines as $line) {
                    if ($line['line_type'] === 'catalog') {
                        $line['warehouse_id'] = $data['warehouse_id'];
                    }

                    $this->saveLine->execute($actor, $order, $line);
                }

                $confirmed = $this->confirmOrder->execute($actor, $order);
                $remaining = $this->paymentCalculator->remainingAmount($confirmed);
                $paymentTotal = $this->paymentTotal($payments);
                $fulfillmentMode = $data['fulfillment_mode'] ?? 'pickup';

                if (Decimal::compare($paymentTotal, $remaining) > 0) {
                    throw ValidationException::withMessages([
                        'payments' => "Le total des paiements dépasse le montant dû autorisé de {$remaining}.",
                    ]);
                }

                if ($fulfillmentMode !== 'delivery' && Decimal::compare($paymentTotal, $remaining) !== 0) {
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

                if ($fulfillmentMode !== 'delivery') {
                    $completed = $this->fulfillOrder->execute($actor, $confirmed->fresh())
                        ->load(['store:id,name,code', 'customer:id,display_name', 'paymentAllocations.payment.financialAccount']);
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
            /** @var \App\Models\SalesOrderLine $line */
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
            'tax_rate_id' => null,
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
     * @param list<array<string, mixed>> $payments
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
