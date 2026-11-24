<?php

namespace App\Actions\Finance\Customers;

use App\Enums\PaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentRefund;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceClosedPeriodGuard;
use App\Services\SalesOrderPaymentCalculator;
use App\Services\Sandbox\SandboxContext;
use App\Support\BusinessTime;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Allocation / controlled de-allocation of a customer advance (a posted Payment carrying customer_id whose amount is not
 * fully allocated). The Payment itself - and therefore the financial-account movement - is never touched: only the order
 * allocations change, under locks, with closed-period protection and audit. Nothing is deleted except an allocation row
 * the operator explicitly de-allocates (its audit entry keeps the history); a refunded allocation can never be de-allocated.
 */
class ManageCustomerAdvanceAction
{
    public function __construct(
        private readonly SalesOrderPaymentCalculator $calculator,
        private readonly AuditLogger $audit,
        private readonly FinanceClosedPeriodGuard $periods,
    ) {}

    /** @param array<int, array{sales_order_id:int|string, amount:string}> $allocations */
    public function allocate(User $actor, Payment $payment, array $allocations): Payment
    {
        $store = $this->authorize($actor, $payment);
        $rows = collect($allocations)->map(fn (array $row) => ['order_id' => (int) $row['sales_order_id'], 'amount' => Decimal::positive($row['amount'], 'allocations')])->sortBy('order_id')->values();
        if ($rows->isEmpty() || $rows->pluck('order_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['allocations' => 'Indiquez au moins une commande, une seule fois chacune.']);
        }

        return DB::transaction(function () use ($actor, $payment, $rows, $store) {
            $payment = $this->lockedPayment($payment);
            $orders = SalesOrder::query()->where('organization_id', $payment->organization_id)->where('store_id', $store->getKey())
                ->where('customer_id', $payment->customer_id)->whereIn('id', $rows->pluck('order_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($orders->count() !== $rows->count()) {
                throw ValidationException::withMessages(['allocations' => 'Chaque commande doit appartenir au client de l’avance et au magasin du règlement.']);
            }
            $this->periods->assertOpen($payment->organization_id, [$payment->payment_date, BusinessTime::today($payment->organization)], 'payment_date');
            $available = $this->available($payment);
            $total = $rows->reduce(fn (string $sum, array $row) => Decimal::add($sum, $row['amount']), '0.0000');
            if (Decimal::compare($total, $available) > 0) {
                throw ValidationException::withMessages(['allocations' => 'Le total dépasse le solde disponible de l’avance.']);
            }
            foreach ($rows as $row) {
                $order = $orders->get($row['order_id']);
                if ($order->status !== SalesOrderStatus::Confirmed || $order->sandbox_id !== null || $order->currency_code !== $payment->currency_code) {
                    throw ValidationException::withMessages(['allocations' => "La commande {$order->order_number} ne peut pas recevoir cette avance (statut ou devise)."]);
                }
                if (Decimal::compare($row['amount'], $this->calculator->remainingAmount($order)) > 0) {
                    throw ValidationException::withMessages(['allocations' => "L’affectation dépasse le reste à payer de la commande {$order->order_number}."]);
                }
                $existing = PaymentAllocation::query()->where('organization_id', $payment->organization_id)->where('payment_id', $payment->getKey())
                    ->where('sales_order_id', $order->getKey())->lockForUpdate()->first();
                $allocation = $existing ?? new PaymentAllocation;
                $allocation->organization_id = $payment->organization_id;
                $allocation->payment_id = $payment->getKey();
                $allocation->sales_order_id = $order->getKey();
                $allocation->amount = Decimal::add((string) ($existing->amount ?? '0'), $row['amount']);
                $allocation->save();
                $this->calculator->recalculate($order);
            }
            $this->audit->record('customer_advance.allocated', $actor, $payment->organization, $store, $payment, newValues: [
                'payment_number' => $payment->payment_number, 'allocated' => $total, 'advance_left' => Decimal::subtract($available, $total),
                'order_numbers' => $orders->pluck('order_number')->values()->all(),
            ]);

            return $payment;
        }, 3);
    }

    public function deallocate(User $actor, Payment $payment, SalesOrder $order, string $reason): Payment
    {
        $store = $this->authorize($actor, $payment);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Un motif est obligatoire.']);
        }

        return DB::transaction(function () use ($actor, $payment, $order, $reason, $store) {
            $payment = $this->lockedPayment($payment);
            $order = SalesOrder::query()->where('organization_id', $payment->organization_id)->where('store_id', $store->getKey())->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $allocation = PaymentAllocation::query()->where('organization_id', $payment->organization_id)->where('payment_id', $payment->getKey())
                ->where('sales_order_id', $order->getKey())->lockForUpdate()->firstOrFail();
            if (PaymentRefund::query()->where('organization_id', $payment->organization_id)->where('payment_id', $payment->getKey())->where('sales_order_id', $order->getKey())->exists()) {
                throw ValidationException::withMessages(['payment' => 'Une affectation déjà remboursée ne peut pas être désaffectée : les mouvements restent historiques.']);
            }
            $this->periods->assertOpen($payment->organization_id, [$payment->payment_date, BusinessTime::today($payment->organization)], 'payment_date');
            $amount = (string) $allocation->amount;
            $allocation->delete();
            $this->calculator->recalculate($order);
            $this->audit->record('customer_advance.deallocated', $actor, $payment->organization, $store, $payment, oldValues: ['allocated_to' => $order->order_number, 'amount' => $amount], newValues: [
                'payment_number' => $payment->payment_number, 'reason' => trim($reason), 'advance_available' => $this->available($payment),
            ]);

            return $payment;
        }, 3);
    }

    /** Unallocated part of a posted payment: amount - Σ allocations. */
    public function available(Payment $payment): string
    {
        $allocated = (string) PaymentAllocation::query()->where('organization_id', $payment->organization_id)->where('payment_id', $payment->getKey())->sum('amount');

        return Decimal::subtract((string) $payment->amount, Decimal::normalize($allocated));
    }

    private function lockedPayment(Payment $payment): Payment
    {
        $locked = Payment::query()->where('organization_id', $payment->organization_id)->where('store_id', $payment->store_id)->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== PaymentStatus::Posted || $locked->customer_id === null) {
            throw ValidationException::withMessages(['payment' => 'Seul un règlement client comptabilisé peut être affecté ou désaffecté.']);
        }

        return $locked;
    }

    private function authorize(User $actor, Payment $payment)
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $store = $actor->active_store_id ? Store::query()->where('organization_id', $payment->organization_id)->whereKey($actor->active_store_id)->first() : null;
        abort_unless($store !== null && $store->getKey() === $payment->store_id && $actor->active_organization_id === $payment->organization_id
            && $store->memberships()->where('user_id', $actor->getKey())->exists()
            && $actor->hasPermission($payment->organization_id, 'finance.customers.settlements.manage'), 403);

        return $store;
    }
}
