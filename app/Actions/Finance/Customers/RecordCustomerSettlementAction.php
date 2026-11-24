<?php

namespace App\Actions\Finance\Customers;

use App\Enums\FinancialAccountStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceClosedPeriodGuard;
use App\Services\PaymentNumberGenerator;
use App\Services\SalesOrderPaymentCalculator;
use App\Services\Sandbox\SandboxContext;
use App\Support\BusinessTime;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer-centric settlement: ONE Payment (one real money record, one financial account movement) that is allocated
 * across several confirmed orders of the SAME customer (manual allocation or oldest-due-first), optionally leaving an
 * unallocated ADVANCE (the part of the payment not allocated yet; payments.customer_id marks it).
 *
 * It adapts the existing Payment / PaymentAllocation model - no parallel money table:
 *  - allocations are the existing order-level allocations, so receivables stay order-specific (a payment never reduces
 *    an unrelated order unless the operator allocates to it);
 *  - an advance is NOT revenue and reduces no invoice automatically (it has no allocation until the operator allocates it);
 *  - every affected order must belong to the payment's store, which must be the actor's active store (no cross-store
 *    allocation: payments are store-scoped by design);
 *  - idempotent on (organization, store, client_operation_id), locks accounts + orders (ordered by id) in one transaction,
 *    refuses closed periods and inactive / incompatible accounts, audits, and is unavailable in Test Mode.
 */
class RecordCustomerSettlementAction
{
    public function __construct(
        private readonly PaymentNumberGenerator $numbers,
        private readonly SalesOrderPaymentCalculator $calculator,
        private readonly AuditLogger $audit,
        private readonly FinanceClosedPeriodGuard $periods,
    ) {}

    /**
     * @param  array{financial_account_id:int|string, method:string, amount:string, payment_date:string, reference?:?string, external_reference?:?string, notes?:?string, allocations?:array<int,array{sales_order_id:int|string, amount:string}>}  $data
     */
    public function execute(User $actor, Customer $customer, array $data, string $operationId): Payment
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $store = $actor->active_store_id ? Store::query()->where('organization_id', $customer->organization_id)->whereKey($actor->active_store_id)->first() : null;
        abort_unless($store !== null
            && $actor->active_organization_id === $customer->organization_id
            && $store->organization_id === $customer->organization_id
            && $store->memberships()->where('user_id', $actor->getKey())->exists()
            && $actor->hasPermission($customer->organization_id, 'finance.customers.settlements.manage')
            && $actor->hasPermission($customer->organization_id, 'payments.create'), 403);
        $organization = $customer->organization;
        $method = PaymentMethod::from($data['method']);
        $amount = Decimal::positive($data['amount'], 'amount');
        $allocations = collect($data['allocations'] ?? [])->map(fn (array $row) => [
            'order_id' => (int) $row['sales_order_id'], 'amount' => Decimal::positive($row['amount'], 'allocations'),
        ])->sortBy('order_id')->values();
        if ($allocations->pluck('order_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['allocations' => 'Une commande ne peut apparaître qu’une fois dans le règlement.']);
        }
        $allocated = $allocations->reduce(fn (string $sum, array $row) => Decimal::add($sum, $row['amount']), '0.0000');
        if (Decimal::compare($allocated, $amount) > 0) {
            throw ValidationException::withMessages(['allocations' => 'La somme des affectations dépasse le montant du règlement.']);
        }

        try {
            return DB::transaction(function () use ($actor, $customer, $organization, $store, $data, $operationId, $method, $amount, $allocations, $allocated) {
                // The account row is locked FIRST (it always exists): serializing here keeps the idempotency lookup below from taking
                // concurrent gap locks on a not-yet-existing operation row (inserter deadlocks between identical requests).
                $account = FinancialAccount::query()->where('organization_id', $organization->getKey())->whereKey($data['financial_account_id'])->lockForUpdate()->firstOrFail();
                $existing = Payment::query()->where('organization_id', $organization->getKey())->where('store_id', $store->getKey())
                    ->where('client_operation_id', $operationId)->where('operation_sequence', 1)->lockForUpdate()->first();
                if ($existing) {
                    return $this->assertSameRequest($existing, $customer, $data, $amount);
                }
                if ($account->status !== FinancialAccountStatus::Active) {
                    throw ValidationException::withMessages(['financial_account_id' => 'Seul un compte financier actif peut recevoir un règlement.']);
                }
                if (! $account->acceptsMethod($method)) {
                    throw ValidationException::withMessages(['financial_account_id' => 'Le compte financier sélectionné n’est pas compatible avec ce mode de paiement.']);
                }
                $paymentDate = $data['payment_date'];
                if ($paymentDate !== BusinessTime::today($organization) && ! $actor->hasPermission($organization->getKey(), 'payments.backdate')) {
                    abort(403, 'You are not authorized to change the Payment business date.');
                }
                $this->periods->assertOpen($organization, $paymentDate, 'payment_date');

                $orders = SalesOrder::query()->where('organization_id', $organization->getKey())->where('store_id', $store->getKey())
                    ->where('customer_id', $customer->getKey())->whereIn('id', $allocations->pluck('order_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($orders->count() !== $allocations->count()) {
                    throw ValidationException::withMessages(['allocations' => 'Chaque commande doit appartenir à ce client et au magasin actif.']);
                }
                foreach ($allocations as $row) {
                    /** @var SalesOrder $order */
                    $order = $orders->get($row['order_id']);
                    if ($order->status !== SalesOrderStatus::Confirmed || $order->sandbox_id !== null) {
                        throw ValidationException::withMessages(['allocations' => "La commande {$order->order_number} n’est pas une commande confirmée."]);
                    }
                    if ($order->currency_code !== $account->currency_code) {
                        throw ValidationException::withMessages(['allocations' => "La devise de la commande {$order->order_number} diffère de celle du compte financier."]);
                    }
                    if (Decimal::compare($row['amount'], $this->calculator->remainingAmount($order)) > 0) {
                        throw ValidationException::withMessages(['allocations' => "L’affectation dépasse le reste à payer de la commande {$order->order_number}."]);
                    }
                }

                $payment = new Payment;
                $payment->organization_id = $organization->getKey();
                $payment->store_id = $store->getKey();
                $payment->financial_account_id = $account->getKey();
                $payment->customer_id = $customer->getKey();
                $payment->payment_number = $this->numbers->next($organization);
                $payment->method = $method;
                $payment->status = PaymentStatus::Posted;
                $payment->amount = $amount;
                $payment->currency_code = $account->currency_code;
                $payment->payment_date = $paymentDate;
                $payment->reference = $data['reference'] ?? null;
                $payment->external_reference = $data['external_reference'] ?? null;
                $payment->notes = $data['notes'] ?? null;
                $payment->received_by_user_id = $actor->getKey();
                $payment->client_operation_id = $operationId;
                $payment->operation_sequence = 1;
                $payment->save();

                foreach ($allocations as $row) {
                    $allocation = new PaymentAllocation;
                    $allocation->organization_id = $organization->getKey();
                    $allocation->payment_id = $payment->getKey();
                    $allocation->sales_order_id = $row['order_id'];
                    $allocation->amount = $row['amount'];
                    $allocation->save();
                    $this->calculator->recalculate($orders->get($row['order_id']));
                }
                $this->audit->record('customer_settlement.posted', $actor, $organization, $store, $payment, newValues: [
                    'payment_number' => $payment->payment_number, 'customer_id' => $customer->getKey(), 'amount' => $amount, 'allocated' => $allocated,
                    'advance' => Decimal::subtract($amount, $allocated), 'order_numbers' => $orders->pluck('order_number')->values()->all(),
                    'financial_account_id' => $account->getKey(), 'payment_date' => $paymentDate,
                ]);

                return $payment;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = Payment::query()->where('organization_id', $customer->organization_id)->where('store_id', $store->getKey())
                ->where('client_operation_id', $operationId)->where('operation_sequence', 1)->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->assertSameRequest($existing, $customer, $data, $amount);
        }
    }

    /**
     * Oldest-due-first PREVIEW (never persisted): the customer's open confirmed orders of the active store ordered by due date
     * (undated last), then sale date, then id, filled until the amount is used. The remainder is the proposed advance.
     *
     * @return array{allocations: list<array{sales_order_id:int, order_number:string, due_date:?string, amount:string, remaining:string}>, advance: string}
     */
    public function previewOldestDueFirst(User $actor, Customer $customer, string $amount): array
    {
        $store = $actor->active_store_id ? Store::query()->where('organization_id', $customer->organization_id)->whereKey($actor->active_store_id)->first() : null;
        abort_unless($store !== null && $actor->active_organization_id === $customer->organization_id && $actor->hasPermission($customer->organization_id, 'finance.customers.settlements.manage'), 403);
        $left = Decimal::positive($amount, 'amount');
        $rows = [];
        $orders = SalesOrder::query()->where('organization_id', $customer->organization_id)->where('store_id', $store->getKey())
            ->where('customer_id', $customer->getKey())->where('status', SalesOrderStatus::Confirmed->value)->whereNull('sandbox_id')
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->orderBy('sale_date')->orderBy('id')->get();
        foreach ($orders as $order) {
            if (Decimal::compare($left, '0') <= 0) {
                break;
            }
            $remaining = $this->calculator->remainingAmount($order);
            if (Decimal::compare($remaining, '0') <= 0) {
                continue;
            }
            $take = Decimal::compare($remaining, $left) < 0 ? $remaining : $left;
            $rows[] = ['sales_order_id' => $order->getKey(), 'order_number' => $order->order_number, 'due_date' => $order->due_date?->toDateString(), 'amount' => $take, 'remaining' => $remaining];
            $left = Decimal::subtract($left, $take);
        }

        return ['allocations' => $rows, 'advance' => $left];
    }

    private function assertSameRequest(Payment $existing, Customer $customer, array $data, string $amount): Payment
    {
        if ($existing->customer_id !== $customer->getKey() || Decimal::compare((string) $existing->amount, $amount) !== 0
            || (int) $existing->financial_account_id !== (int) $data['financial_account_id']) {
            throw ValidationException::withMessages(['client_operation_id' => 'Cette opération a déjà été enregistrée avec des données différentes.']);
        }

        return $existing;
    }
}
