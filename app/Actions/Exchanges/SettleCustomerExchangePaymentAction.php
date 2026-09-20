<?php

namespace App\Actions\Exchanges;

use App\Actions\Payments\RecordSalesOrderPaymentsAction;
use App\Models\CustomerExchange;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettleCustomerExchangePaymentAction
{
    public function __construct(private readonly RecordSalesOrderPaymentsAction $payments, private readonly AuditLogger $audit) {}

    /** @param list<array<string,mixed>> $entries */
    public function execute(User $actor, CustomerExchange $exchange, array $entries, string $operationId): CustomerExchange
    {
        abort_unless($actor->active_organization_id === $exchange->organization_id && $actor->active_store_id === $exchange->store_id, 404);
        abort_unless($actor->hasPermission($exchange->organization_id, 'sales_exchanges.process'), 403);
        if ($exchange->status !== 'awaiting_settlement' || Decimal::compare($exchange->difference_amount, '0') <= 0) {
            throw ValidationException::withMessages(['exchange' => 'Cet échange n’attend pas de paiement complémentaire.']);
        }

        $already = $exchange->payments()->where('payments.status', 'posted')->get()->reduce(
            fn (string $sum, $payment) => Decimal::add($sum, $payment->amount), '0.0000',
        );
        $remaining = Decimal::subtract($exchange->difference_amount, $already);
        $submitted = collect($entries)->reduce(fn (string $sum, array $entry) => Decimal::add($sum, Decimal::positive($entry['amount'])), '0.0000');
        if (Decimal::compare($submitted, $remaining) > 0) {
            throw ValidationException::withMessages(['payments' => 'Le paiement dépasse la différence restante de l’échange.']);
        }

        $created = $this->payments->execute($actor, $exchange->salesOrder, $entries, $operationId);

        return DB::transaction(function () use ($actor, $exchange, $created) {
            $exchange = CustomerExchange::query()->where('organization_id', $exchange->organization_id)->whereKey($exchange->id)->lockForUpdate()->firstOrFail();
            $sync = $created->mapWithKeys(fn ($payment) => [$payment->id => ['organization_id' => $exchange->organization_id]])->all();
            $exchange->payments()->syncWithoutDetaching($sync);
            $paid = $exchange->payments()->where('payments.status', 'posted')->get()->reduce(
                fn (string $sum, $payment) => Decimal::add($sum, $payment->amount), '0.0000',
            );
            $this->audit->record('sales_exchange.settlement_recorded', $actor, $exchange->organization, $exchange->store, $exchange, newValues: [
                'direction' => 'customer_payment', 'payment_ids' => $created->pluck('id')->all(), 'paid' => $paid,
            ]);
            if (Decimal::compare($paid, $exchange->difference_amount) >= 0) $this->complete($actor, $exchange);

            return $exchange->fresh(['payments', 'customerReturn.refunds']);
        }, 3);
    }

    private function complete(User $actor, CustomerExchange $exchange): void
    {
        $exchange->settlement_status = 'settled';
        $exchange->status = 'completed';
        $exchange->completed_at = now();
        $exchange->completed_by_user_id = $actor->id;
        $exchange->save();
        $this->audit->record('sales_exchange.settled', $actor, $exchange->organization, $exchange->store, $exchange, newValues: ['direction' => 'customer_payment']);
        $this->audit->record('sales_exchange.completed', $actor, $exchange->organization, $exchange->store, $exchange, newValues: ['settlement' => 'customer_payment']);
    }
}
