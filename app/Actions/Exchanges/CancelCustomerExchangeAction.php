<?php

namespace App\Actions\Exchanges;

use App\Actions\Returns\CancelCustomerReturnAction;
use App\Models\CustomerExchange;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelCustomerExchangeAction
{
    public function __construct(private readonly CancelCustomerReturnAction $returns, private readonly AuditLogger $audit) {}

    public function execute(User $actor, CustomerExchange $exchange, string $reason): CustomerExchange
    {
        abort_unless($actor->active_organization_id === $exchange->organization_id && $actor->active_store_id === $exchange->store_id, 404);
        abort_unless($actor->hasPermission($exchange->organization_id, 'sales_exchanges.process'), 403);
        if (trim($reason) === '') throw ValidationException::withMessages(['reason' => 'Le motif d’annulation est obligatoire.']);
        if ($exchange->sales_order_addendum_id !== null || in_array($exchange->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['exchange' => 'Un échange dont les nouveaux articles ont été remis ne peut pas être annulé.']);
        }

        if ($exchange->customerReturn->status === 'draft') {
            $this->returns->execute($actor, $exchange->customerReturn, $reason);
        }

        return DB::transaction(function () use ($actor, $exchange, $reason) {
            $exchange = CustomerExchange::query()->where('organization_id', $exchange->organization_id)->whereKey($exchange->id)->lockForUpdate()->firstOrFail();
            $exchange->status = 'cancelled';
            $exchange->settlement_status = 'cancelled';
            $exchange->cancelled_at = now();
            $exchange->cancelled_by_user_id = $actor->id;
            $exchange->cancellation_reason = trim($reason);
            $exchange->save();
            $this->audit->record('sales_exchange.cancelled', $actor, $exchange->organization, $exchange->store, $exchange, newValues: [
                'reason' => trim($reason), 'return_preserved' => $exchange->customerReturn->status === 'received',
            ]);
            return $exchange;
        }, 3);
    }
}
