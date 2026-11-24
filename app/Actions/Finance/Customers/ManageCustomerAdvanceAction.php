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

    /**
     * @param  array<int, array{sales_order_id:int|string, amount:string}>  $allocations
     * @param  ?string  $effectiveDate  business day the allocation takes effect on the orders (default today). The payment's own date - the
     *                                  treasury receipt - is never touched, so an advance received in a closed period can still be applied
     *                                  in an open one; only the EFFECTIVE date must fall in an open period.
     */
    public function allocate(User $actor, Payment $payment, array $allocations, ?string $effectiveDate = null): Payment
    {
        $store = $this->authorize($actor, $payment);
        $rows = collect($allocations)->map(fn (array $row) => ['order_id' => (int) $row['sales_order_id'], 'amount' => Decimal::positive($row['amount'], 'allocations')])->sortBy('order_id')->values();
        if ($rows->isEmpty() || $rows->pluck('order_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['allocations' => 'Indiquez au moins une commande, une seule fois chacune.']);
        }

        return DB::transaction(function () use ($actor, $payment, $rows, $store, $effectiveDate) {
            $payment = $this->lockedPayment($payment);
            $today = BusinessTime::today($payment->organization);
            $effective = $effectiveDate ?: $today;
            $paymentDay = substr((string) $payment->getRawOriginal('payment_date'), 0, 10);
            if ($effective > $today || $effective < $paymentDay) {
                throw ValidationException::withMessages(['effective_date' => 'La date d’effet doit être comprise entre la date de réception de l’avance et aujourd’hui.']);
            }
            if ($effective !== $today && ! $actor->hasPermission($payment->organization_id, 'payments.backdate')) {
                abort(403, 'You are not authorized to change the Payment business date.');
            }
            $orders = SalesOrder::query()->where('organization_id', $payment->organization_id)->where('store_id', $store->getKey())
                ->where('customer_id', $payment->customer_id)->whereIn('id', $rows->pluck('order_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($orders->count() !== $rows->count()) {
                throw ValidationException::withMessages(['allocations' => 'Chaque commande doit appartenir au client de l’avance et au magasin du règlement.']);
            }
            // Only the effective date must be in an open period: the receipt itself stays immutable in its (possibly closed) period.
            $this->periods->assertOpen($payment->organization_id, $effective, 'effective_date');
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
                $existingDay = $existing ? substr((string) ($existing->getRawOriginal('effective_date') ?: $paymentDay), 0, 10) : null;
                if ($existing && $existingDay !== $effective) {
                    throw ValidationException::withMessages(['allocations' => "Cette avance est déjà affectée à {$order->order_number} avec une autre date d’effet : désaffectez d’abord."]);
                }
                $allocation = $existing ?? new PaymentAllocation;
                $allocation->organization_id = $payment->organization_id;
                $allocation->payment_id = $payment->getKey();
                $allocation->sales_order_id = $order->getKey();
                $allocation->effective_date = $effective;
                $allocation->amount = Decimal::add((string) ($existing->amount ?? '0'), $row['amount']);
                $allocation->save();
                $this->calculator->recalculate($order);
            }
            $this->audit->record('customer_advance.allocated', $actor, $payment->organization, $store, $payment, newValues: [
                'payment_number' => $payment->payment_number, 'allocated' => $total, 'advance_left' => Decimal::subtract($available, $total), 'effective_date' => $effective,
                'payment_date' => $paymentDay,
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
            // The release takes effect today: it must fall in an open period. The original receipt (and the allocation's own effective
            // date) may lie in closed periods - they stay reproducible through payment_allocation_releases.
            $today = BusinessTime::today($payment->organization);
            $this->periods->assertOpen($payment->organization_id, $today, 'payment_date');
            $amount = (string) $allocation->amount;
            $effective = substr((string) ($allocation->getRawOriginal('effective_date') ?: $payment->getRawOriginal('payment_date')), 0, 10);
            DB::table('payment_allocation_releases')->insert([
                'organization_id' => $payment->organization_id, 'payment_id' => $payment->getKey(), 'sales_order_id' => $order->getKey(), 'amount' => $amount,
                'effective_date' => $effective, 'released_date' => $today, 'reason' => trim($reason), 'released_by_user_id' => $actor->getKey(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $allocation->delete();
            $this->calculator->recalculate($order);
            $this->audit->record('customer_advance.deallocated', $actor, $payment->organization, $store, $payment, oldValues: ['allocated_to' => $order->order_number, 'amount' => $amount], newValues: [
                'payment_number' => $payment->payment_number, 'reason' => trim($reason), 'advance_available' => $this->available($payment),
            ]);

            return $payment;
        }, 3);
    }

    /**
     * Apply (part of) an available advance to an OPENING BALANCE (a historical customer debt). No sales order, invoice, VAT or second
     * receipt is created: the advance is the one real receipt, the settlement row only moves it from "available advance" to
     * "opening balance settled" on its effective date.
     */
    public function settleOpeningBalance(User $actor, Payment $payment, int $openingBalanceId, string $amount, ?string $effectiveDate = null): Payment
    {
        $this->authorize($actor, $payment);
        $amount = Decimal::positive($amount, 'amount');

        return DB::transaction(function () use ($actor, $payment, $openingBalanceId, $amount, $effectiveDate) {
            $payment = $this->lockedPayment($payment);
            $today = BusinessTime::today($payment->organization);
            $effective = $effectiveDate ?: $today;
            $paymentDay = substr((string) $payment->getRawOriginal('payment_date'), 0, 10);
            if ($effective > $today || $effective < $paymentDay) {
                throw ValidationException::withMessages(['effective_date' => 'La date d’effet doit être comprise entre la date de réception de l’avance et aujourd’hui.']);
            }
            if ($effective !== $today && ! $actor->hasPermission($payment->organization_id, 'payments.backdate')) {
                abort(403, 'You are not authorized to change the Payment business date.');
            }
            $opening = DB::table('customer_opening_balances')->where('organization_id', $payment->organization_id)->where('customer_id', $payment->customer_id)
                ->where('id', $openingBalanceId)->lockForUpdate()->first();
            if ($opening === null || $opening->status !== 'posted' || $opening->currency_code !== $payment->currency_code || Decimal::compare((string) $opening->amount, '0') <= 0) {
                throw ValidationException::withMessages(['opening_balance' => 'Ce solde d’ouverture ne peut pas être réglé par cette avance (statut, devise ou sens).']);
            }
            if ($effective < substr((string) $opening->effective_date, 0, 10)) {
                throw ValidationException::withMessages(['effective_date' => 'Le règlement ne peut pas précéder la date du solde d’ouverture.']);
            }
            $this->periods->assertOpen($payment->organization_id, $effective, 'effective_date');
            $outstanding = Decimal::subtract(Decimal::normalize((string) $opening->amount), $this->settledOnOpening($payment->organization_id, $openingBalanceId));
            if (Decimal::compare($amount, $outstanding) > 0) {
                throw ValidationException::withMessages(['amount' => 'Le montant dépasse le reste dû sur ce solde d’ouverture.']);
            }
            if (Decimal::compare($amount, $this->available($payment)) > 0) {
                throw ValidationException::withMessages(['amount' => 'Le montant dépasse le solde disponible de l’avance.']);
            }
            DB::table('customer_opening_balance_settlements')->insert([
                'organization_id' => $payment->organization_id, 'customer_id' => $payment->customer_id, 'opening_balance_id' => $openingBalanceId, 'payment_id' => $payment->getKey(),
                'amount' => $amount, 'effective_date' => $effective, 'created_by_user_id' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('customer_opening_balance.settled', $actor, $payment->organization, $payment->store, $payment, newValues: [
                'payment_number' => $payment->payment_number, 'opening_balance_id' => $openingBalanceId, 'amount' => $amount, 'effective_date' => $effective,
                'outstanding_after' => Decimal::subtract($outstanding, $amount),
            ]);

            return $payment;
        }, 3);
    }

    public function releaseOpeningSettlement(User $actor, Payment $payment, int $settlementId, string $reason): Payment
    {
        $this->authorize($actor, $payment);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Un motif est obligatoire.']);
        }

        return DB::transaction(function () use ($actor, $payment, $settlementId, $reason) {
            $payment = $this->lockedPayment($payment);
            $row = DB::table('customer_opening_balance_settlements')->where('organization_id', $payment->organization_id)->where('payment_id', $payment->getKey())
                ->where('id', $settlementId)->whereNull('released_date')->lockForUpdate()->first();
            abort_if($row === null, 404);
            $today = BusinessTime::today($payment->organization);
            $this->periods->assertOpen($payment->organization_id, $today, 'payment_date');
            DB::table('customer_opening_balance_settlements')->where('id', $settlementId)->update(['released_date' => $today, 'release_reason' => trim($reason), 'updated_at' => now()]);
            $this->audit->record('customer_opening_balance.settlement_released', $actor, $payment->organization, $payment->store, $payment,
                oldValues: ['opening_balance_id' => $row->opening_balance_id, 'amount' => (string) $row->amount], newValues: ['reason' => trim($reason), 'released_date' => $today]);

            return $payment;
        }, 3);
    }

    /** Amount of an opening balance currently settled by applied (not released) advances. */
    public function settledOnOpening(int $organizationId, int $openingBalanceId): string
    {
        return Decimal::normalize((string) DB::table('customer_opening_balance_settlements')->where('organization_id', $organizationId)
            ->where('opening_balance_id', $openingBalanceId)->whereNull('released_date')->sum('amount'));
    }

    /** Unallocated part of a posted payment: amount - Σ order allocations - Σ opening-balance settlements still applied. */
    public function available(Payment $payment): string
    {
        $allocated = (string) PaymentAllocation::query()->where('organization_id', $payment->organization_id)->where('payment_id', $payment->getKey())->sum('amount');
        $opening = (string) DB::table('customer_opening_balance_settlements')->where('organization_id', $payment->organization_id)->where('payment_id', $payment->getKey())->whereNull('released_date')->sum('amount');

        return Decimal::subtract(Decimal::subtract((string) $payment->amount, Decimal::normalize($allocated)), Decimal::normalize($opening));
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
