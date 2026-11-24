<?php

namespace App\Actions\Finance\Customers;

use App\Actions\Payments\ReversePaymentAction;
use App\Enums\FinancialAccountStatus;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SalesOrderPaymentCalculator;
use App\Services\Sandbox\SandboxContext;
use App\Support\BusinessTime;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Incoming customer cheques and commercial effects (never the outgoing supplier instruments of finance_payment_instruments).
 *
 * Lifecycle (state changes are recorded in customer_instrument_events and audited):
 *   received -> in_portfolio -> submitted -> cleared | rejected      received / in_portfolio / submitted -> cancelled
 *   cleared  -> rejected  (a bank return AFTER crediting: reverses the clearing payment, only with payments.reverse)
 * Money rule: receiving, holding or submitting an instrument moves NO money and reduces NO receivable (a pending instrument is a
 * promise on paper, shown separately as "pending instruments"). Only CLEARANCE creates the financial transaction: one Payment
 * on the destination account at the clearing date, through RecordCustomerSettlementAction, idempotent on the instrument's own key,
 * so a duplicate clearance returns the same payment and can never post twice. It is allocated to the related order (up to its
 * remaining balance) and any excess stays an unallocated advance. Rejection of a not-yet-cleared instrument has no financial effect
 * to undo (nothing was booked). Legacy Posted cheque payments are untouched and are never reclassified as bank-cleared.
 */
class ManageCustomerInstrumentAction
{
    private const TRANSITIONS = [
        'received' => ['in_portfolio', 'submitted', 'cancelled'],
        'in_portfolio' => ['submitted', 'cancelled'],
        'submitted' => ['cleared', 'rejected', 'cancelled'],
        'cleared' => ['rejected'],
    ];

    public function __construct(
        private readonly RecordCustomerSettlementAction $settlement,
        private readonly ReversePaymentAction $reversal,
        private readonly SalesOrderPaymentCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $data */
    public function receive(User $actor, Customer $customer, array $data, string $idempotencyKey): int
    {
        $store = $this->authorize($actor, $customer->organization_id);
        $amount = Decimal::positive($data['amount'], 'amount');
        $type = $data['type'];
        if (! in_array($type, ['cheque', 'effet'], true)) {
            throw ValidationException::withMessages(['type' => 'Type d’instrument inconnu.']);
        }

        return DB::transaction(function () use ($actor, $customer, $data, $idempotencyKey, $store, $amount, $type) {
            $existing = DB::table('customer_payment_instruments')->where('organization_id', $customer->organization_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->customer_id !== (int) $customer->getKey() || Decimal::compare((string) $existing->amount, $amount) !== 0) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Cette opération a déjà été enregistrée avec des données différentes.']);
                }

                return (int) $existing->id;
            }
            $account = FinancialAccount::query()->where('organization_id', $customer->organization_id)->whereKey($data['financial_account_id'])->lockForUpdate()->firstOrFail();
            if ($account->status !== FinancialAccountStatus::Active) {
                throw ValidationException::withMessages(['financial_account_id' => 'Le compte de destination doit être actif.']);
            }
            $orderId = null;
            if (! empty($data['sales_order_id'])) {
                $order = SalesOrder::query()->where('organization_id', $customer->organization_id)->where('customer_id', $customer->getKey())->whereKey($data['sales_order_id'])->first();
                if ($order === null || $order->currency_code !== $account->currency_code) {
                    throw ValidationException::withMessages(['sales_order_id' => 'La commande doit appartenir à ce client et partager la devise du compte.']);
                }
                $orderId = $order->getKey();
            }
            $duplicate = DB::table('customer_payment_instruments')->where('organization_id', $customer->organization_id)->where('type', $type)
                ->where('instrument_number', trim($data['instrument_number']))->where('issuing_bank', $data['issuing_bank'] ?? null)->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['instrument_number' => 'Cet instrument est déjà enregistré (même type, numéro et banque).']);
            }
            $id = DB::table('customer_payment_instruments')->insertGetId([
                'organization_id' => $customer->organization_id, 'store_id' => $store->getKey(), 'customer_id' => $customer->getKey(), 'sales_order_id' => $orderId, 'type' => $type,
                'instrument_number' => trim($data['instrument_number']), 'customer_reference' => $data['customer_reference'] ?? null, 'issuing_bank' => $data['issuing_bank'] ?? null,
                'amount' => $amount, 'currency_code' => $account->currency_code, 'received_date' => $data['received_date'], 'expected_collection_date' => $data['expected_collection_date'] ?? null,
                'maturity_date' => $data['maturity_date'] ?? null, 'financial_account_id' => $account->getKey(), 'state' => 'received', 'idempotency_key' => $idempotencyKey,
                'notes' => $data['notes'] ?? null, 'created_by_user_id' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($customer->organization_id, $id, null, 'received', $data['received_date'], $actor, $data['notes'] ?? null);
            $this->audit->record('customer_instrument.received', $actor, $customer->organization, $store, $customer, newValues: [
                'instrument_id' => $id, 'type' => $type, 'instrument_number' => trim($data['instrument_number']), 'amount' => $amount, 'customer_id' => $customer->getKey(),
            ]);

            return $id;
        });
    }

    public function transition(User $actor, int $instrumentId, string $to, string $date, ?string $note): void
    {
        $organizationId = (int) $actor->active_organization_id;
        $store = $this->authorize($actor, $organizationId);
        DB::transaction(function () use ($actor, $instrumentId, $to, $date, $note, $organizationId, $store) {
            $row = DB::table('customer_payment_instruments')->where('organization_id', $organizationId)->where('id', $instrumentId)->lockForUpdate()->first();
            abort_if($row === null, 404);
            // Already in the requested state: a replayed request is a no-op (never a second financial transaction).
            if ($row->state === $to) {
                return;
            }
            if (! in_array($to, self::TRANSITIONS[$row->state] ?? [], true)) {
                throw ValidationException::withMessages(['state' => 'Transition impossible depuis l’état actuel.']);
            }
            if ($row->state === 'cleared' && $to === 'rejected') {
                abort_unless($actor->hasPermission($organizationId, 'payments.reverse'), 403);
            }
            $customer = Customer::query()->where('organization_id', $organizationId)->findOrFail($row->customer_id);
            $paymentId = $row->cleared_payment_id;

            if ($to === 'cleared') {
                $payment = $this->clear($actor, $customer, $row, $date, $store);
                $paymentId = $payment->getKey();
            }
            if ($row->state === 'cleared' && $to === 'rejected') {
                $payment = Payment::query()->where('organization_id', $organizationId)->whereKey($row->cleared_payment_id)->firstOrFail();
                $this->reversal->execute($actor, $payment, 'Instrument impayé : '.($note ?: $row->instrument_number));
            }
            DB::table('customer_payment_instruments')->where('id', $instrumentId)->update(['state' => $to, 'cleared_payment_id' => $paymentId, 'updated_at' => now()]);
            $this->event($organizationId, $instrumentId, $row->state, $to, $date, $actor, $note);
            $this->audit->record('customer_instrument.'.$to, $actor, $customer->organization, $store, $customer, oldValues: ['state' => $row->state], newValues: [
                'instrument_id' => $instrumentId, 'instrument_number' => $row->instrument_number, 'state' => $to, 'date' => $date, 'payment_id' => $paymentId,
            ]);
        });
    }

    private function clear(User $actor, Customer $customer, object $row, string $date, Store $store): Payment
    {
        if ((int) $row->store_id !== (int) $store->getKey()) {
            throw ValidationException::withMessages(['state' => 'L’instrument doit être compensé depuis le magasin qui l’a reçu.']);
        }
        if ($date < substr((string) $row->received_date, 0, 10)) {
            throw ValidationException::withMessages(['date' => 'La date de compensation ne peut pas précéder la date de réception.']);
        }
        if ($date > BusinessTime::today($customer->organization)) {
            throw ValidationException::withMessages(['date' => 'La date de compensation ne peut pas être dans le futur.']);
        }
        $allocations = [];
        $amount = Decimal::normalize((string) $row->amount);
        if ($row->sales_order_id) {
            $order = SalesOrder::query()->where('organization_id', $customer->organization_id)->whereKey($row->sales_order_id)->lockForUpdate()->first();
            if ($order !== null && $order->status->value === 'confirmed') {
                $remaining = $this->calculator->remainingAmount($order);
                $take = Decimal::compare($remaining, $amount) < 0 ? $remaining : $amount;
                if (Decimal::compare($take, '0') > 0) {
                    $allocations[] = ['sales_order_id' => $order->getKey(), 'amount' => $take];
                }
            }
        }

        return $this->settlement->execute($actor, $customer, [
            'financial_account_id' => $row->financial_account_id, 'method' => $row->type === 'cheque' ? 'cheque' : 'bank_transfer', 'amount' => $amount, 'payment_date' => $date,
            'reference' => $row->instrument_number, 'external_reference' => $row->customer_reference, 'notes' => 'Compensation '.$row->type.' '.$row->instrument_number, 'allocations' => $allocations,
        ], $row->idempotency_key);
    }

    private function event(int $organizationId, int $instrumentId, ?string $from, string $to, string $date, User $actor, ?string $note): void
    {
        DB::table('customer_instrument_events')->insert(['organization_id' => $organizationId, 'instrument_id' => $instrumentId, 'from_state' => $from, 'to_state' => $to,
            'event_date' => $date, 'note' => $note, 'actor_user_id' => $actor->getKey(), 'created_at' => now()]);
    }

    private function authorize(User $actor, int $organizationId): Store
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $store = $actor->active_store_id ? Store::query()->where('organization_id', $organizationId)->whereKey($actor->active_store_id)->first() : null;
        abort_unless($store !== null && $actor->active_organization_id === $organizationId && $actor->hasPermission($organizationId, 'finance.customers.instruments.manage')
            && $store->memberships()->where('user_id', $actor->getKey())->exists(), 403);

        return $store;
    }
}
