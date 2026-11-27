<?php

namespace App\Enums;

/** Closed value set of the `invoice_issuance` policy (Phase 1 Step 11). Skeleton: stored and audited, no gate reads it yet. */
enum InvoiceIssuancePolicy: string
{
    case AfterFullPayment = 'after_full_payment';
    case OnConfirmation = 'on_confirmation';
    case OnDelivery = 'on_delivery';

    /** Safe default = current, most restrictive behavior. */
    public static function default(): self
    {
        return self::AfterFullPayment;
    }
}
