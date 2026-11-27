<?php

namespace Tests\Feature\Policies;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Settings\UpdateOrganizationPoliciesAction;
use App\Enums\CreditLimitEnforcementPolicy;
use App\Enums\InvoiceIssuancePolicy;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Policies\PolicyResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DocumentTestCase;

/**
 * Phase 1 Step 11: typed policy skeleton. No behavior change (due dates identical with or without a policy row),
 * resolver decision tables, enum validation, authorization, tenant isolation, audit old -> new, future-only effect.
 */
class TypedPolicySkeletonTest extends DocumentTestCase
{
    private function admin(Organization $organization, Store $store, array $permissions): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($organization, $user, $permissions, roleName: 'Policy '.uniqid());
        $this->addStoreMember($store, $user);
        $this->activate($user, $organization, $store);

        return $user;
    }

    /** @return array{User, Organization, Store} */
    private function base(): array
    {
        [$owner, $organization, $store] = $this->documentFixture();

        return [$owner, $organization->fresh(), $store];
    }

    /** A newly confirmed order in an existing organization (same shape as documentFixture). */
    private function newConfirmedOrder(User $owner, Organization $organization, Store $store): SalesOrder
    {
        $customer = $this->createCustomer($organization, 'Client', [
            'company_name' => 'Client SARL', 'email' => 'billing@example.test', 'phone' => '0600000000',
            'tax_identifier' => 'ICE-123', 'billing_address' => '1 Commerce Street',
        ]);
        $order = $this->createDraftOrder($owner, $organization, $store, $customer, ['sale_date' => '2026-05-29']);
        $this->addCustomLine($owner, $order, ['description' => 'Consulting', 'unit_price_excl_tax' => '100.0000']);

        return app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
    }

    // --- no behavior change -------------------------------------------------------------------------------------

    public function test_organizations_default_to_current_behavior(): void
    {
        [, $organization] = $this->base();
        $resolver = app(PolicyResolver::class);

        $this->assertSame(InvoiceIssuancePolicy::AfterFullPayment, $resolver->invoiceIssuance($organization));
        $this->assertSame(CreditLimitEnforcementPolicy::Warn, $resolver->creditLimitEnforcement($organization));
        $this->assertNull($organization->sales_order_payment_term_days);
        $this->assertNull($organization->invoice_payment_term_days);
        $this->assertSame(7, $resolver->salesOrderPaymentTermDays($organization));
        $this->assertSame(30, $resolver->invoicePaymentTermDays($organization));
    }

    public function test_due_dates_are_identical_without_a_policy_and_with_overrides_equal_to_the_environment(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $this->assertSame($order->sale_date->copy()->addDays(7)->toDateString(), $order->due_date->toDateString());
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->assertSame($invoice->invoice_date->copy()->addDays(30)->toDateString(), $invoice->due_date->toDateString());

        [$owner2, $organization2, $store2] = $this->base();
        DB::table('organizations')->where('id', $organization2->id)->update(['sales_order_payment_term_days' => 7, 'invoice_payment_term_days' => 30]);
        $order2 = $this->newConfirmedOrder($owner2, $organization2->fresh(), $store2);
        $invoice2 = $this->issueInvoice($owner2, $this->createInvoice($owner2, $order2));
        $this->assertSame($order->due_date->toDateString(), $order2->due_date->toDateString());
        $this->assertSame($invoice->due_date->toDateString(), $invoice2->due_date->toDateString());
    }

    public function test_environment_values_still_drive_organizations_without_an_override(): void
    {
        config()->set('payments.sales_order_payment_term_days', 3);
        config()->set('documents.invoice.payment_term_days', 12);
        [$owner, , , $order] = $this->documentFixture();
        $this->assertSame($order->sale_date->copy()->addDays(3)->toDateString(), $order->due_date->toDateString());
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->assertSame($invoice->invoice_date->copy()->addDays(12)->toDateString(), $invoice->due_date->toDateString());
    }

    public function test_issuance_and_credit_policies_do_not_change_any_outcome(): void
    {
        foreach (InvoiceIssuancePolicy::cases() as $issuance) {
            [$owner, $organization, $store] = $this->base();
            DB::table('organizations')->where('id', $organization->id)->update([
                'invoice_issuance' => $issuance->value, 'credit_limit_enforcement' => CreditLimitEnforcementPolicy::Block->value,
            ]);
            $order = $this->newConfirmedOrder($owner, $organization->fresh(), $store);
            $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
            $this->assertSame('issued', $invoice->status->value, $issuance->value);
            $this->assertSame($invoice->invoice_date->copy()->addDays(30)->toDateString(), $invoice->due_date->toDateString());
        }
    }

    // --- resolver decision tables -------------------------------------------------------------------------------

    public static function storedEnumValues(): array
    {
        return [
            'valid on_delivery' => ['on_delivery', InvoiceIssuancePolicy::OnDelivery],
            'valid on_confirmation' => ['on_confirmation', InvoiceIssuancePolicy::OnConfirmation],
            'valid after_full_payment' => ['after_full_payment', InvoiceIssuancePolicy::AfterFullPayment],
            'unknown falls back' => ['whenever', InvoiceIssuancePolicy::AfterFullPayment],
            'empty falls back' => ['', InvoiceIssuancePolicy::AfterFullPayment],
            'null falls back' => [null, InvoiceIssuancePolicy::AfterFullPayment],
        ];
    }

    #[DataProvider('storedEnumValues')]
    public function test_invoice_issuance_resolution(?string $stored, InvoiceIssuancePolicy $expected): void
    {
        $organization = (new Organization)->setAttribute('invoice_issuance', $stored);
        $this->assertSame($expected, (new PolicyResolver)->invoiceIssuance($organization));
    }

    public static function storedCreditValues(): array
    {
        return [
            'warn' => ['warn', CreditLimitEnforcementPolicy::Warn],
            'block' => ['block', CreditLimitEnforcementPolicy::Block],
            'require_approval' => ['require_approval', CreditLimitEnforcementPolicy::RequireApproval],
            'unknown falls back' => ['nope', CreditLimitEnforcementPolicy::Warn],
            'null falls back' => [null, CreditLimitEnforcementPolicy::Warn],
        ];
    }

    #[DataProvider('storedCreditValues')]
    public function test_credit_limit_resolution(?string $stored, CreditLimitEnforcementPolicy $expected): void
    {
        $organization = (new Organization)->setAttribute('credit_limit_enforcement', $stored);
        $this->assertSame($expected, (new PolicyResolver)->creditLimitEnforcement($organization));
    }

    public static function dayValues(): array
    {
        return [
            'null inherits' => [null, 7],
            'zero is valid' => [0, 0],
            'valid' => [45, 45],
            'numeric string' => ['45', 45],
            'upper bound' => [365, 365],
            'beyond bound inherits' => [366, 7],
            'negative inherits' => [-1, 7],
            'non numeric inherits' => ['abc', 7],
            'fractional inherits' => ['3.5', 7],
        ];
    }

    #[DataProvider('dayValues')]
    public function test_payment_term_resolution_is_bounded_with_environment_fallback(mixed $stored, int $expected): void
    {
        config()->set('payments.sales_order_payment_term_days', 7);
        $organization = (new Organization)->setAttribute('sales_order_payment_term_days', $stored);
        $this->assertSame($expected, (new PolicyResolver)->salesOrderPaymentTermDays($organization));
    }

    public function test_invoice_payment_term_override_and_fallback(): void
    {
        config()->set('documents.invoice.payment_term_days', 30);
        $resolver = new PolicyResolver;
        $this->assertSame(60, $resolver->invoicePaymentTermDays((new Organization)->setAttribute('invoice_payment_term_days', 60)));
        $this->assertSame(30, $resolver->invoicePaymentTermDays(new Organization));
        config()->set('documents.invoice.payment_term_days', -5);
        $this->assertSame(0, $resolver->invoicePaymentTermDays(new Organization), 'Environment fallback keeps the historical max(0, ...) clamp.');
    }

    // --- update action: validation, authorization, tenancy, audit, future-only -----------------------------------

    public function test_enum_and_bound_validation_rejects_invalid_values(): void
    {
        [$owner, $organization] = $this->base();
        $action = app(UpdateOrganizationPoliciesAction::class);
        foreach ([['invoice_issuance' => 'never'], ['credit_limit_enforcement' => 'ignore'], ['invoice_issuance' => null],
            ['sales_order_payment_term_days' => 366], ['invoice_payment_term_days' => -1], ['sales_order_payment_term_days' => 'x']] as $changes) {
            try {
                $action->execute($owner, $organization, $changes, 'Raison valable');
                $this->fail('Expected validation failure for '.json_encode($changes));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('after_full_payment', $organization->fresh()->invoice_issuance);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'organization.policy_changed']);
    }

    public function test_unknown_policy_keys_are_refused(): void
    {
        [$owner, $organization] = $this->base();
        $this->expectException(HttpException::class);
        app(UpdateOrganizationPoliciesAction::class)->execute($owner, $organization, ['status' => 'closed'], 'Raison valable');
    }

    public function test_a_reason_is_required(): void
    {
        [$owner, $organization] = $this->base();
        $this->expectException(ValidationException::class);
        app(UpdateOrganizationPoliciesAction::class)->execute($owner, $organization, ['invoice_issuance' => 'on_delivery'], ' ');
    }

    public function test_owner_change_is_audited_old_to_new_with_reason(): void
    {
        [$owner, $organization] = $this->base();
        app(UpdateOrganizationPoliciesAction::class)->execute($owner, $organization, [
            'invoice_issuance' => 'on_delivery', 'credit_limit_enforcement' => 'require_approval', 'sales_order_payment_term_days' => 15,
        ], 'Passage grossiste');

        $fresh = $organization->fresh();
        $this->assertSame('on_delivery', $fresh->invoice_issuance);
        $this->assertSame('require_approval', $fresh->credit_limit_enforcement);
        $this->assertSame(15, (int) $fresh->sales_order_payment_term_days);
        $logs = AuditLog::query()->where('event', 'organization.policy_changed')->where('organization_id', $organization->id)->get();
        $this->assertCount(3, $logs);
        $log = $logs->first(fn ($l) => ($l->new_values['policy'] ?? null) === 'invoice_issuance');
        $this->assertSame('after_full_payment', $log->old_values['value']);
        $this->assertSame('on_delivery', $log->new_values['value']);
        $this->assertSame('Passage grossiste', $log->new_values['reason']);
        $this->assertSame('future_documents_only', $log->new_values['effect']);
        $this->assertSame($owner->id, (int) $log->actor_id);
        $days = $logs->first(fn ($l) => ($l->new_values['policy'] ?? null) === 'sales_order_payment_term_days');
        $this->assertNull($days->old_values['value']);
        $this->assertSame(15, $days->new_values['value']);
    }

    public function test_unchanged_values_are_not_audited(): void
    {
        [$owner, $organization] = $this->base();
        app(UpdateOrganizationPoliciesAction::class)->execute($owner, $organization, ['invoice_issuance' => 'after_full_payment', 'invoice_payment_term_days' => null], 'Aucun changement');
        $this->assertDatabaseMissing('audit_logs', ['event' => 'organization.policy_changed']);
    }

    public function test_each_policy_requires_its_named_permission(): void
    {
        [, $organization, $store] = $this->base();
        $billing = $this->admin($organization, $store, ['settings.billing.manage']);
        $credit = $this->admin($organization, $store, ['customers.manage_credit']);
        $legacy = $this->admin($organization, $store, ['settings.update', 'organizations.update']);
        $action = app(UpdateOrganizationPoliciesAction::class);

        $action->execute($billing, $organization, ['invoice_issuance' => 'on_confirmation'], 'Facturation');
        $action->execute($credit, $organization->fresh(), ['credit_limit_enforcement' => 'block'], 'Credit');
        foreach ([
            [$billing, ['credit_limit_enforcement' => 'warn']], [$credit, ['invoice_issuance' => 'on_delivery']], [$credit, ['invoice_payment_term_days' => 10]],
            [$legacy, ['invoice_issuance' => 'on_delivery']], [$billing, ['invoice_issuance' => 'on_delivery', 'credit_limit_enforcement' => 'warn']],
        ] as [$user, $changes]) {
            try {
                $action->execute($user, $organization->fresh(), $changes, 'Tentative');
                $this->fail('Expected 403');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $fresh = $organization->fresh();
        $this->assertSame('on_confirmation', $fresh->invoice_issuance);
        $this->assertSame('block', $fresh->credit_limit_enforcement);
    }

    public function test_tenant_isolation_org_a_cannot_change_org_b(): void
    {
        [$ownerA, $orgA] = $this->base();
        [, $orgB] = $this->base();
        try {
            app(UpdateOrganizationPoliciesAction::class)->execute($ownerA, $orgB, ['invoice_issuance' => 'on_delivery'], 'Intrusion');
            $this->fail('Expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame('after_full_payment', $orgB->fresh()->invoice_issuance);
        $this->assertSame('after_full_payment', $orgA->fresh()->invoice_issuance);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'organization.policy_changed']);
    }

    public function test_holder_of_the_permission_in_org_a_cannot_act_on_org_b_even_when_b_is_active(): void
    {
        [, $orgA, $storeA] = $this->base();
        [, $orgB, $storeB] = $this->base();
        $user = $this->admin($orgA, $storeA, ['settings.billing.manage']);
        $this->activate($user, $orgB, $storeB); // active org B, but no membership/permission in B
        try {
            app(UpdateOrganizationPoliciesAction::class)->execute($user, $orgB, ['invoice_issuance' => 'on_delivery'], 'Intrusion');
            $this->fail('Expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame('after_full_payment', $orgB->fresh()->invoice_issuance);
    }

    public function test_a_policy_change_only_affects_future_documents(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture();
        $issuedBefore = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $invoiceDue = $issuedBefore->due_date->toDateString();
        $orderDue = $order->due_date->toDateString();

        app(UpdateOrganizationPoliciesAction::class)->execute($owner, $organization->fresh(), ['sales_order_payment_term_days' => 60, 'invoice_payment_term_days' => 90], 'Nouveaux delais');

        $this->assertSame($invoiceDue, $issuedBefore->fresh()->due_date->toDateString());
        $this->assertSame($orderDue, $order->fresh()->due_date->toDateString());

        $newOrder = $this->newConfirmedOrder($owner, $organization->fresh(), $store);
        $this->assertSame($newOrder->sale_date->copy()->addDays(60)->toDateString(), $newOrder->due_date->toDateString());
        $newInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $newOrder));
        $this->assertSame($newInvoice->invoice_date->copy()->addDays(90)->toDateString(), $newInvoice->due_date->toDateString());
        $this->assertSame($invoiceDue, $issuedBefore->fresh()->due_date->toDateString());
    }

    public function test_clearing_an_override_returns_to_the_environment_value(): void
    {
        [$owner, $organization] = $this->base();
        $action = app(UpdateOrganizationPoliciesAction::class);
        $action->execute($owner, $organization, ['invoice_payment_term_days' => 45], 'Delai client');
        $this->assertSame(45, app(PolicyResolver::class)->invoicePaymentTermDays($organization->fresh()));
        $action->execute($owner, $organization->fresh(), ['invoice_payment_term_days' => null], 'Retour defaut');
        $this->assertSame(30, app(PolicyResolver::class)->invoicePaymentTermDays($organization->fresh()));
        $this->assertSame(2, AuditLog::query()->where('event', 'organization.policy_changed')->count());
    }

    // --- provisioning -------------------------------------------------------------------------------------------

    public function test_permissions_are_in_the_catalogue_and_the_provisioning_migration_preserves_rights(): void
    {
        $this->assertSame(2, Permission::query()->whereIn('key', ['settings.billing.manage', 'customers.manage_credit'])->count());

        [, $organization, $store] = $this->base();
        $holder = $this->admin($organization, $store, ['settings.update']);
        $bystander = $this->admin($organization, $store, ['customers.view']);
        $migration = require base_path('database/migrations/2026_11_25_000002_provision_policy_permissions.php');
        $migration->up();
        $migration->up(); // idempotent

        $this->assertTrue($holder->hasPermission($organization, 'settings.billing.manage'));
        $this->assertTrue($holder->hasPermission($organization, 'customers.manage_credit'));
        $this->assertFalse($bystander->hasPermission($organization, 'settings.billing.manage'));
        $this->assertFalse($bystander->hasPermission($organization, 'customers.manage_credit'));
    }
}
