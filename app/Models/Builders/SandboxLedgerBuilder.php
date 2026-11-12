<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent builder of the routed Test ledger models. The sandbox ownership
 * scope is part of the model's identity: no caller (withoutGlobalScopes(),
 * withoutGlobalScope('sandbox_ledger'), withoutGlobalScopesExcept([])) can drop
 * it, so a Test query can never be widened beyond the active sandbox.
 */
class SandboxLedgerBuilder extends Builder
{
    public const SCOPE = 'sandbox_ledger';

    public function withoutGlobalScope($scope)
    {
        if ($scope === self::SCOPE) {
            return $this;
        }

        return parent::withoutGlobalScope($scope);
    }
}
