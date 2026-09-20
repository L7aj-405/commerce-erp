<?php

namespace App\Actions\Exchanges;

use App\Actions\Pos\AddItemsToCompletedPosOrderAction;
use App\Models\CustomerExchange;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FulfillCustomerExchangeReplacementAction
{
    public function __construct(private readonly AddItemsToCompletedPosOrderAction $addItems, private readonly AuditLogger $audit) {}

    public function execute(User $actor, CustomerExchange $exchange): CustomerExchange
    {
        abort_unless($actor->active_organization_id === $exchange->organization_id && $actor->active_store_id === $exchange->store_id, 404);
        abort_unless($actor->hasPermission($exchange->organization_id, 'sales_exchanges.process'), 403);
        if ($exchange->status !== 'awaiting_replacement' || $exchange->customerReturn->status !== 'received') {
            throw ValidationException::withMessages(['exchange' => 'Le retour physique doit être reçu avant la remise des nouveaux articles.']);
        }

        $addendum = $this->addItems->execute($actor, $exchange->salesOrder, $exchange->client_operation_id, $exchange->replacement_items);

        return DB::transaction(function () use ($actor, $exchange, $addendum) {
            $exchange = CustomerExchange::query()->where('organization_id', $exchange->organization_id)->whereKey($exchange->id)->lockForUpdate()->firstOrFail();
            if ($exchange->sales_order_addendum_id === null) {
                $exchange->sales_order_addendum_id = $addendum->id;
                $exchange->new_items_total = $addendum->added_total;
                $exchange->difference_amount = Decimal::subtract($addendum->added_total, $exchange->returned_total);
                $exchange->replacement_fulfilled_at = now();
                $zero = Decimal::compare($exchange->difference_amount, '0.0000') === 0;
                $exchange->status = $zero ? 'completed' : 'awaiting_settlement';
                $exchange->settlement_status = $zero ? 'settled' : 'pending';
                if ($zero) {
                    $exchange->completed_at = now();
                    $exchange->completed_by_user_id = $actor->id;
                }
                $exchange->save();
                $this->audit->record('sales_exchange.replacement_fulfilled', $actor, $exchange->organization, $exchange->store, $exchange, newValues: [
                    'exchange_number' => $exchange->exchange_number, 'sales_order_addendum_id' => $addendum->id,
                    'new_items_total' => $exchange->new_items_total, 'difference_amount' => $exchange->difference_amount,
                ]);
                if ($zero) $this->audit->record('sales_exchange.completed', $actor, $exchange->organization, $exchange->store, $exchange, newValues: ['settlement' => 'zero']);
            }
            return $exchange->fresh(['salesOrderAddendum.lines', 'customerReturn.creditNotes']);
        }, 3);
    }
}
