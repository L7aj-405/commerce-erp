<?php

namespace App\Actions\Exchanges;

use App\Actions\Returns\RefundCustomerReturnAction;
use App\Models\CustomerExchange;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettleCustomerExchangeRefundAction
{
    public function __construct(private readonly RefundCustomerReturnAction $refunds, private readonly AuditLogger $audit) {}

    public function execute(User $actor, CustomerExchange $exchange, Payment $payment, string $amount, string $reason, string $operationId): CustomerExchange
    {
        abort_unless($actor->active_organization_id === $exchange->organization_id && $actor->active_store_id === $exchange->store_id, 404);
        abort_unless($actor->hasPermission($exchange->organization_id, 'sales_exchanges.process'), 403);
        if ($exchange->status !== 'awaiting_settlement' || Decimal::compare($exchange->difference_amount, '0') >= 0) {
            throw ValidationException::withMessages(['exchange' => 'Cet échange n’attend pas de remboursement.']);
        }

        $amount = Decimal::positive($amount);
        $target = Decimal::subtract('0.0000', $exchange->difference_amount);
        $already = $exchange->customerReturn->refunds()->where('status', 'posted')->get()->reduce(
            fn (string $sum, $refund) => Decimal::add($sum, $refund->amount), '0.0000',
        );
        if (Decimal::compare($amount, Decimal::subtract($target, $already)) > 0) {
            throw ValidationException::withMessages(['amount' => 'Le remboursement dépasse la différence restante de l’échange.']);
        }

        $refund = $this->refunds->execute($actor, $exchange->customerReturn, $payment, $amount, $reason, $operationId);

        return DB::transaction(function () use ($actor, $exchange, $refund, $target) {
            $exchange = CustomerExchange::query()->where('organization_id', $exchange->organization_id)->whereKey($exchange->id)->lockForUpdate()->firstOrFail();
            $refunded = $exchange->customerReturn->refunds()->where('status', 'posted')->get()->reduce(
                fn (string $sum, $row) => Decimal::add($sum, $row->amount), '0.0000',
            );
            $this->audit->record('sales_exchange.settlement_recorded', $actor, $exchange->organization, $exchange->store, $exchange, newValues: [
                'direction' => 'customer_refund', 'payment_refund_id' => $refund->id, 'refunded' => $refunded,
            ]);
            if (Decimal::compare($refunded, $target) >= 0) {
                $exchange->settlement_status = 'settled';
                $exchange->status = 'completed';
                $exchange->completed_at = now();
                $exchange->completed_by_user_id = $actor->id;
                $exchange->save();
                $this->audit->record('sales_exchange.settled', $actor, $exchange->organization, $exchange->store, $exchange, newValues: ['direction' => 'customer_refund']);
                $this->audit->record('sales_exchange.completed', $actor, $exchange->organization, $exchange->store, $exchange, newValues: ['settlement' => 'customer_refund']);
            }
            return $exchange->fresh(['payments', 'customerReturn.refunds']);
        }, 3);
    }
}
