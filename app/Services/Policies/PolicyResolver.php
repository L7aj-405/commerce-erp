<?php

namespace App\Services\Policies;

use App\Enums\CreditLimitEnforcementPolicy;
use App\Enums\InvoiceIssuancePolicy;
use App\Models\Organization;

/**
 * Single read point of the typed organization policies (Phase 1 Step 11, design D-F2: typed columns + resolver).
 *
 * Pure with respect to its input row: no query, no side effect. Resolution = the organization value when it is valid,
 * otherwise the platform default. An unknown / out-of-range stored value never leaks: it resolves to the default.
 *
 * The two payment-term day values fall back to the historical environment values (config) when the organization has
 * no override, which keeps every existing organization's due dates identical. This class is the ONLY place allowed
 * to read those two config keys (enforced by PolicyConfigArchitectureTest).
 *
 * invoice_issuance and credit_limit_enforcement are exposed for the future gates but are NOT consulted by any
 * business Action yet: they cannot change an outcome.
 */
final class PolicyResolver
{
    public const MIN_PAYMENT_TERM_DAYS = 0;

    public const MAX_PAYMENT_TERM_DAYS = 365;

    public function invoiceIssuance(Organization $organization): InvoiceIssuancePolicy
    {
        return InvoiceIssuancePolicy::tryFrom((string) $organization->getAttribute('invoice_issuance')) ?? InvoiceIssuancePolicy::default();
    }

    public function creditLimitEnforcement(Organization $organization): CreditLimitEnforcementPolicy
    {
        return CreditLimitEnforcementPolicy::tryFrom((string) $organization->getAttribute('credit_limit_enforcement')) ?? CreditLimitEnforcementPolicy::default();
    }

    /** Days between the sale date and a sales order's due date (stamped once at confirmation). */
    public function salesOrderPaymentTermDays(Organization $organization): int
    {
        return $this->days($organization->getAttribute('sales_order_payment_term_days'))
            ?? max(0, (int) config('payments.sales_order_payment_term_days', 7));
    }

    /** Days between the invoice date and an invoice's due date (stamped once at issuance). */
    public function invoicePaymentTermDays(Organization $organization): int
    {
        return $this->days($organization->getAttribute('invoice_payment_term_days'))
            ?? max(0, (int) config('documents.invoice.payment_term_days', 30));
    }

    /** A stored override is honored only when it is an integer inside the bounds; otherwise null (= inherit). */
    private function days(mixed $stored): ?int
    {
        if ($stored === null || $stored === '' || ! is_numeric($stored) || (string) (int) $stored !== (string) $stored) {
            return null;
        }
        $days = (int) $stored;

        return $days >= self::MIN_PAYMENT_TERM_DAYS && $days <= self::MAX_PAYMENT_TERM_DAYS ? $days : null;
    }
}
