<?php

namespace App\Actions\Settings;

use App\Actions\Concerns\MatchesActorTenant;
use App\Enums\CreditLimitEnforcementPolicy;
use App\Enums\InvoiceIssuancePolicy;
use App\Models\Organization;
use App\Models\User;
use App\Services\Policies\PolicyAudit;
use App\Services\Policies\PolicyResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Changes the typed organization policies (Phase 1 Step 11). Each key needs its own named permission, a reason,
 * and records one audited old -> new event. The change only affects documents stamped AFTERWARDS: due dates are
 * stamped once at confirmation / issuance and are never recomputed. The acting organization must be the actor's
 * trusted active organization (or the MCP connection's), never a client-supplied one.
 *
 * Skeleton scope: invoice_issuance and credit_limit_enforcement are stored and audited but no gate reads them yet.
 * A payment-term value of null clears the override (inherit the environment default).
 *
 * Accepted $changes keys: invoice_issuance, credit_limit_enforcement, sales_order_payment_term_days, invoice_payment_term_days.
 */
class UpdateOrganizationPoliciesAction
{
    use MatchesActorTenant;

    /** policy key => permission required to change it. */
    public const PERMISSIONS = [
        'invoice_issuance' => 'settings.billing.manage',
        'sales_order_payment_term_days' => 'settings.billing.manage',
        'invoice_payment_term_days' => 'settings.billing.manage',
        'credit_limit_enforcement' => 'customers.manage_credit',
    ];

    private const DAY_KEYS = ['sales_order_payment_term_days', 'invoice_payment_term_days'];

    public function __construct(private readonly PolicyAudit $audit) {}

    /** @param array<string, mixed> $changes */
    public function execute(User $actor, Organization $organization, array $changes, string $reason): Organization
    {
        abort_if($changes === [] || array_diff(array_keys($changes), array_keys(self::PERMISSIONS)) !== [], 422, 'Unknown policy or nothing to change.');

        abort_unless($this->actorOrganizationMatches($actor, $organization) && $organization->status === 'active', 403);
        foreach (array_keys($changes) as $key) {
            abort_unless($actor->hasPermission($organization->getKey(), self::PERMISSIONS[$key]), 403);
        }

        $dayRule = ['nullable', 'integer', 'min:'.PolicyResolver::MIN_PAYMENT_TERM_DAYS, 'max:'.PolicyResolver::MAX_PAYMENT_TERM_DAYS];
        $rules = [
            'invoice_issuance' => ['required', Rule::in(array_column(InvoiceIssuancePolicy::cases(), 'value'))],
            'credit_limit_enforcement' => ['required', Rule::in(array_column(CreditLimitEnforcementPolicy::cases(), 'value'))],
            'sales_order_payment_term_days' => $dayRule,
            'invoice_payment_term_days' => $dayRule,
        ];
        Validator::make($changes + ['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:500']] + array_intersect_key($rules, $changes))->validate();

        return DB::transaction(function () use ($actor, $organization, $changes, $reason) {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            foreach ($changes as $key => $value) {
                if (in_array($key, self::DAY_KEYS, true) && $value !== null) {
                    $value = (int) $value;
                }
                $old = $locked->getAttribute($key);
                if ($old === $value) {
                    continue;
                }
                $locked->setAttribute($key, $value);
                $this->audit->record($actor, $locked, $key, $old, $value, $reason);
            }
            if ($locked->isDirty()) {
                $locked->save();
            }

            return $locked;
        });
    }
}
