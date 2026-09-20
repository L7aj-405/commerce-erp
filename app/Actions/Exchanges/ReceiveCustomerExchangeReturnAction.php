<?php

namespace App\Actions\Exchanges;

use App\Actions\Returns\ReceiveCustomerReturnAction;
use App\Models\CustomerExchange;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveCustomerExchangeReturnAction
{
    public function __construct(private readonly ReceiveCustomerReturnAction $returns, private readonly AuditLogger $audit) {}

    public function execute(User $actor, CustomerExchange $exchange): CustomerExchange
    {
        abort_unless($actor->active_organization_id === $exchange->organization_id && $actor->active_store_id === $exchange->store_id, 404);
        abort_unless($actor->hasPermission($exchange->organization_id, 'sales_exchanges.process'), 403);
        if ($exchange->status !== 'awaiting_return_receipt') throw ValidationException::withMessages(['exchange' => 'Cet échange n’attend pas de réception.']);

        $this->returns->execute($actor, $exchange->customerReturn);

        return DB::transaction(function () use ($actor, $exchange) {
            $exchange = CustomerExchange::query()->where('organization_id', $exchange->organization_id)->whereKey($exchange->id)->lockForUpdate()->firstOrFail();
            if ($exchange->status === 'awaiting_return_receipt') {
                $exchange->status = 'awaiting_replacement';
                $exchange->return_received_at = now();
                $exchange->save();
                $this->audit->record('sales_exchange.return_received', $actor, $exchange->organization, $exchange->store, $exchange, newValues: [
                    'exchange_number' => $exchange->exchange_number, 'customer_return_id' => $exchange->customer_return_id,
                ]);
            }
            return $exchange->fresh(['customerReturn.creditNotes']);
        }, 3);
    }
}
