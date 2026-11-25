<?php

namespace App\Enums;

/** OPT-FC-004: whether an issued invoice requires the order to be fully paid first. Per customer. */
enum CustomerInvoicingPolicy: string
{
    case AfterFullPayment = 'after_full_payment';
    case BeforePaymentAllowed = 'before_payment_allowed';

    public function label(): string
    {
        return match ($this) {
            self::AfterFullPayment => 'Facturation après paiement intégral',
            self::BeforePaymentAllowed => 'Facturation autorisée avant paiement',
        };
    }

    /** Approved defaults: B2C / Particulier = after payment; B2B / Entreprise = before payment allowed. */
    public static function defaultFor(?CustomerType $type): self
    {
        return $type === CustomerType::Company ? self::BeforePaymentAllowed : self::AfterFullPayment;
    }
}
