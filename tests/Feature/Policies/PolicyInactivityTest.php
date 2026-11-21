<?php

namespace Tests\Feature\Policies;

use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Settings\UpdateOrganizationPoliciesAction;
use App\Enums\CreditLimitEnforcementPolicy;
use App\Enums\InvoiceIssuancePolicy;
use App\Models\AuditLog;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\DocumentTestCase;

/**
 * Phase 1 final UAT (Step 11, check 10): invoice_issuance and credit_limit_enforcement stay behaviorally inactive.
 *
 * TypedPolicySkeletonTest only issues invoices through DocumentTestCase::issueInvoice(), which adds a temporary
 * payment first, so it never exercises the refusal path. These tests call IssueInvoiceAction on an UNPAID order.
 */
class PolicyInactivityTest extends DocumentTestCase
{
    public function test_unpaid_invoice_is_refused_identically_for_every_issuance_and_credit_policy_value(): void
    {
        $baseline = null;
        foreach (InvoiceIssuancePolicy::cases() as $issuance) {
            foreach (CreditLimitEnforcementPolicy::cases() as $credit) {
                [$owner, $organization, , $order] = $this->documentFixture();
                DB::table('organizations')->where('id', $organization->id)->update([
                    'invoice_issuance' => $issuance->value, 'credit_limit_enforcement' => $credit->value,
                ]);
                $invoice = $this->createInvoice($owner, $order->fresh());

                try {
                    app(IssueInvoiceAction::class)->execute($owner, $invoice);
                    $this->fail("an unpaid order must not be invoiced ({$issuance->value}/{$credit->value})");
                } catch (ValidationException $e) {
                    $errors = $e->errors();
                }

                $this->assertSame(['invoice' => [IssueInvoiceAction::FULL_PAYMENT_REQUIRED_MESSAGE]], $errors, "{$issuance->value}/{$credit->value}");
                $this->assertNotSame('issued', $invoice->fresh()->status->value);
                $this->assertSame(0, Invoice::query()->where('organization_id', $organization->id)->where('status', 'issued')->count());
                $baseline ??= $errors;
                $this->assertSame($baseline, $errors, 'the refusal never depends on the stored policy values');
            }
        }
    }

    public function test_changing_the_policies_through_the_action_does_not_change_order_confirmation_or_due_dates(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture();
        $before = $order->due_date->toDateString();

        app(UpdateOrganizationPoliciesAction::class)->execute($owner, $organization->fresh(), [
            'invoice_issuance' => 'on_confirmation', 'credit_limit_enforcement' => 'block',
        ], 'Verification inactivite');

        $this->assertSame(2, AuditLog::query()->where('event', 'organization.policy_changed')->count(), 'both changes are audited');
        $this->assertSame($before, $order->fresh()->due_date->toDateString(), 'the already confirmed order is untouched');

        $customer = $this->createCustomer($organization->fresh(), 'Autre client', ['company_name' => 'Autre SARL', 'email' => 'b@example.test', 'phone' => '0600000001', 'tax_identifier' => 'ICE-9', 'billing_address' => '2 Rue']);
        $draft = $this->createDraftOrder($owner, $organization->fresh(), $store, $customer, ['sale_date' => '2026-05-29']);
        $this->addCustomLine($owner, $draft, ['description' => 'Service', 'unit_price_excl_tax' => '999999.0000']);
        $confirmed = app(ConfirmSalesOrderAction::class)->execute($owner, $draft)->fresh();

        $this->assertSame('confirmed', $confirmed->status->value, 'credit_limit_enforcement=block must not block a confirmation yet');
        $this->assertSame($confirmed->sale_date->copy()->addDays(7)->toDateString(), $confirmed->due_date->toDateString());
    }
}
