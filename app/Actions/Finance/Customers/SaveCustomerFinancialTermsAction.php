<?php

namespace App\Actions\Finance\Customers;

use App\Enums\CustomerInvoicingPolicy;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Sandbox\SandboxContext;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Per-customer financial conditions (credit limit, payment term, deposit, tolerance, policy, status). Audited with old/new values. */
class SaveCustomerFinancialTermsAction
{
    public const POLICIES = ['warn', 'block'];

    public const STATUSES = ['normal', 'watch', 'blocked'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string,mixed> $data */
    public function execute(User $actor, Customer $customer, array $data): void
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        abort_unless($actor->active_organization_id === $customer->organization_id
            && $actor->hasPermission($customer->organization_id, 'finance.customers.terms.manage'), 403);
        $enabled = (bool) ($data['credit_enabled'] ?? false);
        $limit = ($data['credit_limit'] ?? '') === '' || $data['credit_limit'] === null ? null : Decimal::nonNegative($data['credit_limit'], 'credit_limit');
        if ($enabled && $limit === null) {
            throw ValidationException::withMessages(['credit_limit' => 'Un plafond est obligatoire lorsque le crédit est activé.']);
        }
        $row = [
            'credit_enabled' => $enabled, 'credit_limit' => $limit,
            'payment_term_days' => ($data['payment_term_days'] ?? '') === '' ? null : (int) $data['payment_term_days'],
            'min_deposit_percent' => ($data['min_deposit_percent'] ?? '') === '' ? null : $data['min_deposit_percent'],
            'overdue_tolerance_days' => (int) ($data['overdue_tolerance_days'] ?? 0),
            'policy' => in_array($data['policy'] ?? 'warn', self::POLICIES, true) ? ($data['policy'] ?? 'warn') : 'warn',
            'invoicing_policy' => CustomerInvoicingPolicy::tryFrom((string) ($data['invoicing_policy'] ?? ''))?->value,
            'financial_status' => in_array($data['financial_status'] ?? 'normal', self::STATUSES, true) ? ($data['financial_status'] ?? 'normal') : 'normal',
        ];
        DB::transaction(function () use ($actor, $customer, $row) {
            $old = DB::table('customer_financial_terms')->where('organization_id', $customer->organization_id)->where('customer_id', $customer->getKey())->lockForUpdate()->first();
            // A manual delay that no longer matches the assigned catalogue term detaches it (the term never lies about the days).
            if ($old && $old->payment_term_id !== null && $row['payment_term_days'] !== (int) DB::table('payment_terms')->where('id', $old->payment_term_id)->value('days')) {
                $row['payment_term_id'] = null;
            }
            DB::table('customer_financial_terms')->updateOrInsert(
                ['organization_id' => $customer->organization_id, 'customer_id' => $customer->getKey()],
                $row + ['updated_by_user_id' => $actor->getKey(), 'updated_at' => now()] + ($old ? [] : ['created_at' => now()]),
            );
            $this->audit->record('customer_financial_terms.saved', $actor, $customer->organization, null, $customer,
                oldValues: $old ? array_intersect_key((array) $old, $row) : [], newValues: $row);
        });
    }
}
