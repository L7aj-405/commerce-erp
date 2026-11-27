<?php

namespace App\Enums;

/** Closed value set of the `credit_limit_enforcement` policy (Phase 1 Step 11). Skeleton: stored and audited, no gate reads it yet. */
enum CreditLimitEnforcementPolicy: string
{
    case Warn = 'warn';
    case Block = 'block';
    case RequireApproval = 'require_approval';

    public static function default(): self
    {
        return self::Warn;
    }
}
