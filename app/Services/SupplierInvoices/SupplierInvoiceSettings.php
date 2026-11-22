<?php

namespace App\Services\SupplierInvoices;

use App\Support\Decimal;

/**
 * Typed accessors over config/supplier_invoices.php. The ONLY reader of that config, so a later
 * move to per-organization policies (PolicyResolver) stays local to this class.
 */
final class SupplierInvoiceSettings
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed>|null $values Override (tests); null reads config('supplier_invoices'). */
    public function __construct(?array $values = null)
    {
        $this->values = $values ?? (array) config('supplier_invoices', []);
    }

    public function enabled(): bool
    {
        return (bool) ($this->values['enabled'] ?? false);
    }

    public function roundingTolerance(): string
    {
        return Decimal::nonNegative((string) ($this->values['rounding_tolerance'] ?? '0.05'), 'rounding_tolerance');
    }

    public function moneyScale(): int
    {
        return max(0, min(4, (int) ($this->values['money_scale'] ?? 2)));
    }

    public function duplicateWindowDays(): int
    {
        return max(0, (int) ($this->values['duplicate_window_days'] ?? 7));
    }

    public function requireDistinctApprover(): bool
    {
        return (bool) ($this->values['require_distinct_approver'] ?? false);
    }

    public function requireSupplierIceOnApproval(): bool
    {
        return (bool) ($this->values['require_supplier_ice_on_approval'] ?? false);
    }

    public function paymentTermWarningDays(): ?int
    {
        return $this->nullableDays('payment_term_warning_days');
    }

    public function voidMaxAgeDays(): ?int
    {
        return $this->nullableDays('void_max_age_days');
    }

    private function nullableDays(string $key): ?int
    {
        $value = $this->values[$key] ?? null;

        return $value === null || $value === '' ? null : max(0, (int) $value);
    }
}
