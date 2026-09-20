<?php

namespace App\Actions\Returns;

use App\Models\CustomerReturn;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelCustomerReturnAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, CustomerReturn $customerReturn, string $reason): CustomerReturn
    {
        abort_unless($actor->active_organization_id === $customerReturn->organization_id && $actor->active_store_id === $customerReturn->store_id, 404);
        abort_unless($customerReturn->store()->whereHas('memberships', fn ($query) => $query->where('user_id', $actor->id))->exists(), 404);
        abort_unless($actor->hasPermission($customerReturn->organization_id, 'sales_returns.cancel'), 403);
        if (trim($reason) === '') throw ValidationException::withMessages(['reason' => 'Le motif d’annulation est obligatoire.']);

        return DB::transaction(function () use ($actor, $customerReturn, $reason) {
            $customerReturn = CustomerReturn::query()->whereKey($customerReturn->id)->lockForUpdate()->with(['organization', 'store', 'creditNotes'])->firstOrFail();
            if ($customerReturn->status !== 'draft') throw ValidationException::withMessages(['return' => 'Un retour réceptionné ne peut pas être annulé.']);
            $customerReturn->status = 'cancelled'; $customerReturn->cancelled_at = now(); $customerReturn->cancelled_by_user_id = $actor->id; $customerReturn->cancellation_reason = trim($reason); $customerReturn->save();
            $customerReturn->creditNotes()->where('status', 'draft')->update(['status' => 'cancelled']);
            $this->audit->record('sales_return.cancelled', $actor, $customerReturn->organization, $customerReturn->store, $customerReturn, oldValues: ['status' => 'draft'], newValues: ['status' => 'cancelled', 'reason' => trim($reason)]);
            return $customerReturn;
        });
    }
}
