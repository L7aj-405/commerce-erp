<?php

namespace App\Actions\Payments;

use App\Actions\Payments\Concerns\AuthorizesPaymentAction;
use App\Enums\FinancialAccountStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\FinancialAccount;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentNumberGenerator;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordPaymentAction
{
    use AuthorizesPaymentAction;

    public function __construct(
        private readonly PaymentNumberGenerator $numbers,
        private readonly SalesOrderPaymentCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, SalesOrder $order, array $data, string $operationId, int $operationSequence = 1): Payment
    {
        $this->authorizePayment($actor, $order, 'payments.create');

        try {
            return DB::transaction(function () use ($actor, $order, $data, $operationId, $operationSequence) {
                $order = SalesOrder::query()
                    ->where('organization_id', $order->organization_id)
                    ->where('store_id', $order->store_id)
                    ->whereKey($order->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $method = PaymentMethod::from($data['method']);
                $amount = Decimal::positive($data['amount'], 'amount');
                $paymentDate = $data['payment_date'];
                $account = FinancialAccount::query()
                    ->where('organization_id', $order->organization_id)
                    ->whereKey($data['financial_account_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing = $this->existing($order, $operationId, $operationSequence, true);
                if ($existing) {
                    return $this->matching($existing, $order, $account, $method, $amount, $paymentDate);
                }

                if ($order->status !== SalesOrderStatus::Confirmed) {
                    throw ValidationException::withMessages(['order' => 'Only a confirmed Sales Order can receive a Payment.']);
                }
                if ($account->status !== FinancialAccountStatus::Active) {
                    throw ValidationException::withMessages(['financial_account_id' => 'Only an active Financial Account can receive a Payment.']);
                }
                if ($account->currency_code !== $order->currency_code) {
                    throw ValidationException::withMessages(['financial_account_id' => 'The Financial Account currency must match the Sales Order currency.']);
                }
                $this->assertCompatible($method, $account);
                if ($paymentDate !== now()->toDateString() && ! $actor->hasPermission($order->organization_id, 'payments.backdate')) {
                    abort(403, 'You are not authorized to change the Payment business date.');
                }
                $remaining = $this->calculator->remainingAmount($order);
                if (Decimal::compare($amount, $remaining) > 0) {
                    throw ValidationException::withMessages(['amount' => 'The Payment amount exceeds the Sales Order remaining balance.']);
                }

                $payment = new Payment;
                $payment->organization_id = $order->organization_id;
                $payment->store_id = $order->store_id;
                $payment->financial_account_id = $account->getKey();
                $payment->payment_number = $this->numbers->next($order->organization);
                $payment->method = $method;
                $payment->status = PaymentStatus::Posted;
                $payment->amount = $amount;
                $payment->currency_code = $order->currency_code;
                $payment->payment_date = $paymentDate;
                $payment->reference = $data['reference'] ?? null;
                $payment->external_reference = $data['external_reference'] ?? null;
                $payment->notes = $data['notes'] ?? null;
                $payment->received_by_user_id = $actor->getKey();
                $payment->client_operation_id = $operationId;
                $payment->operation_sequence = $operationSequence;
                $payment->save();

                $allocation = new PaymentAllocation;
                $allocation->organization_id = $order->organization_id;
                $allocation->payment_id = $payment->getKey();
                $allocation->sales_order_id = $order->getKey();
                $allocation->amount = $amount;
                $allocation->save();

                $this->calculator->recalculate($order);
                $this->audit->record('payment.posted', $actor, $order->organization, $order->store, $payment, newValues: [
                    'payment_number' => $payment->payment_number,
                    'order_number' => $order->order_number,
                    'method' => $method->value,
                    'financial_account_id' => $account->getKey(),
                    'amount' => $amount,
                    'payment_date' => $paymentDate,
                ]);

                return $payment->load(['financialAccount:id,name,code,type', 'allocations.salesOrder:id,order_number']);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->existing($order, $operationId, $operationSequence);
            if (! $existing) {
                throw $exception;
            }
            $account = FinancialAccount::query()->where('organization_id', $order->organization_id)->whereKey($data['financial_account_id'])->firstOrFail();

            return $this->matching(
                $existing,
                $order,
                $account,
                PaymentMethod::from($data['method']),
                Decimal::positive($data['amount'], 'amount'),
                $data['payment_date'],
            );
        }
    }

    private function existing(SalesOrder $order, string $operationId, int $sequence, bool $lock = false): ?Payment
    {
        return Payment::query()
            ->where('organization_id', $order->organization_id)
            ->where('store_id', $order->store_id)
            ->where('client_operation_id', $operationId)
            ->where('operation_sequence', $sequence)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->with('allocations')
            ->first();
    }

    private function matching(Payment $payment, SalesOrder $order, FinancialAccount $account, PaymentMethod $method, string $amount, string $date): Payment
    {
        $allocation = $payment->allocations->first();
        if (
            $payment->status !== PaymentStatus::Posted
            || $payment->financial_account_id !== $account->getKey()
            || $payment->method !== $method
            || Decimal::compare($payment->amount, $amount) !== 0
            || $payment->payment_date->toDateString() !== $date
            || ! $allocation
            || $allocation->sales_order_id !== $order->getKey()
            || Decimal::compare($allocation->amount, $amount) !== 0
        ) {
            throw ValidationException::withMessages(['client_operation_id' => 'This Payment operation identifier was already used with different data.']);
        }

        return $payment->load(['financialAccount:id,name,code,type', 'allocations.salesOrder:id,order_number']);
    }

    private function assertCompatible(PaymentMethod $method, FinancialAccount $account): void
    {
        $allowed = config("payments.account_types.{$method->value}", []);
        if (! in_array($account->type->value, $allowed, true)) {
            throw ValidationException::withMessages(['financial_account_id' => 'The selected Financial Account is not compatible with this Payment method.']);
        }
    }
}
