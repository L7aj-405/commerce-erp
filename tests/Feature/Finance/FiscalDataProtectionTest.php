<?php

namespace Tests\Feature\Finance;

use App\Actions\Settings\SetOrganizationFiscalDataModeAction;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\FiscalDocumentProtection;
use App\Services\OrganizationDataReset\OrganizationDataResetBlockedException;
use App\Services\OrganizationDataReset\OrganizationDataResetExecutor;
use App\Services\OrganizationDataReset\OrganizationDataResetPreviewService;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use App\Services\OrganizationDataReset\Selective\SelectiveResetCategoryRegistry;
use App\Services\OrganizationDataReset\Selective\SelectiveResetPlan;
use App\Services\OrganizationDataReset\Selective\SelectiveResetPlanner;
use App\Services\OrganizationDataReset\Selective\SelectiveResetSelection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DocumentTestCase;

/**
 * Sprint 0 / WS1 — issued invoices / credit notes, payments, refunds and
 * outgoing payments of a real (protected) organization can never be deleted
 * by either data-reset engine; declared test data stays cleanable.
 */
class FiscalDataProtectionTest extends DocumentTestCase
{
    public function test_every_organization_is_protected_by_default(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner)->fresh();

        $this->assertSame('protected', $organization->fiscal_data_mode);
        $this->assertNull($organization->fiscal_protection_started_at);
        $this->assertTrue(app(FiscalDocumentProtection::class)->isProtecting($organization));
    }

    public function test_selective_reset_refuses_issued_invoices_and_payments_of_a_protected_organization(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->activate($owner, $organization);

        $invoicePlan = $this->selectivePlan($organization, ['categories' => ['invoices'], 'records' => ['invoices' => [$invoice->id]]]);
        $this->assertFalse($invoicePlan->isExecutable());
        $blocker = collect($invoicePlan->blockers)->firstWhere('code', 'fiscal_protected');
        $this->assertSame('invoices', $blocker['table']);
        $this->assertSame(1, $blocker['count']);
        $this->assertSame([$invoice->invoice_number], $blocker['samples']);

        // The fixture's posted-then-reversed payment is protected history too.
        $paymentPlan = $this->selectivePlan($organization, ['categories' => ['payments']]);
        $this->assertContains('payments', collect($paymentPlan->blockers)->where('code', 'fiscal_protected')->pluck('table')->all());
        $this->assertFalse($paymentPlan->isExecutable());
    }

    public function test_draft_invoices_are_not_fiscal_documents(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        $draft = $this->createInvoice($owner, $order);
        $this->activate($owner, $organization);

        $plan = $this->selectivePlan($organization, ['categories' => ['invoices'], 'records' => ['invoices' => [$draft->id]]]);
        $this->assertSame([], collect($plan->blockers)->where('code', 'fiscal_protected')->all());
    }

    public function test_declared_test_organization_keeps_its_cleanup_workflow(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        $organization->forceFill(['fiscal_data_mode' => 'test'])->save();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->activate($owner, $organization);

        $plan = $this->selectivePlan($organization, ['categories' => ['invoices'], 'records' => ['invoices' => [$invoice->id]]]);
        $this->assertSame([], collect($plan->blockers)->where('code', 'fiscal_protected')->all());
    }

    public function test_full_module_reset_is_blocked_in_preview_and_at_execution(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->activate($owner, $organization);

        $plan = app(OrganizationDataResetPreviewService::class)->preview($organization->fresh(), OrganizationDataResetScope::SalesAndDocuments);
        $codes = array_column($plan->blockers, 'code');
        $this->assertContains('fiscal.protected_documents.invoices', $codes);
        $this->assertContains('fiscal.protected_documents.payments', $codes);
        $this->assertFalse($plan->isExecutable());

        $this->expectException(OrganizationDataResetBlockedException::class);
        app(OrganizationDataResetExecutor::class)->execute($organization->fresh(), $owner, $plan);
    }

    public function test_full_reset_preview_api_returns_the_fiscal_blockers(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture();
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->activate($owner, $organization, $store);

        $this->actingAs($owner)->postJson('/settings/data-reset/preview', ['scope' => 'full_organization'])
            ->assertOk()
            ->assertJsonPath('data.executable', false)
            ->assertJsonFragment(['code' => 'fiscal.protected_documents.invoices']);
    }

    public function test_going_live_protects_only_documents_created_from_then_on(): void
    {
        [$owner, $organization, , $order, ] = $this->documentFixture(total: '300.0000');
        $organization->forceFill(['fiscal_data_mode' => 'test'])->save();
        $account = $this->createFinancialAccount($organization);
        $testPayment = $this->recordPayment($owner, $order, $account, '100.0000');
        $this->activate($owner, $organization);

        $this->travel(1)->minutes();
        app(SetOrganizationFiscalDataModeAction::class)->execute($owner, $organization->fresh(), 'protected', 'Démarrage réel');
        $this->travel(1)->minutes();
        $this->activate($owner, $organization, $order->store);
        $realPayment = $this->recordPayment($owner, $order, $account, '100.0000');
        $this->activate($owner, $organization);

        $organization = $organization->fresh();
        $this->assertSame('protected', $organization->fiscal_data_mode);
        $this->assertNotNull($organization->fiscal_protection_started_at);
        $plan = $this->selectivePlan($organization, ['categories' => ['payments']]);
        $blocker = collect($plan->blockers)->firstWhere('code', 'fiscal_protected');
        $this->assertSame(1, $blocker['count'], 'Only the payment posted after going live is protected.');
        $this->assertSame([$realPayment->payment_number], $blocker['samples']);
        $this->assertNotSame($testPayment->payment_number, $realPayment->payment_number);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'event' => 'organization.fiscal_data_mode_changed']);
    }

    public function test_tenant_users_can_never_declare_test_fiscal_data_only_platform_operators_can(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $action = app(SetOrganizationFiscalDataModeAction::class);

        // Test data lives in Test Mode (sandbox_id): not even the Owner may lift protection from the app.
        try {
            $action->execute($owner, $organization->fresh(), 'test', 'Organisation de démonstration');
            $this->fail('A tenant user must not declare test fiscal data.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('mode test', mb_strtolower($exception->errors()['mode'][0]));
        }
        $this->assertSame('protected', $organization->fresh()->fiscal_data_mode);

        // Platform operator (legacy demonstration organization), then back to protected by the Owner.
        $organization = $action->execute(null, $organization->fresh(), 'test', 'Démo héritée', platformOverride: true);
        $this->assertSame('test', $organization->fiscal_data_mode);
        $organization = $action->execute($owner, $organization, 'protected', 'Mise en production');
        $this->assertSame('protected', $organization->fiscal_data_mode);

        // Once a protected fiscal document exists, even the platform path refuses without --force semantics.
        [$realOwner, $real, , $order] = $this->documentFixture();
        $this->issueInvoice($realOwner, $this->createInvoice($realOwner, $order));
        $this->activate($realOwner, $real);
        try {
            $action->execute($realOwner, $real->fresh(), 'test', 'Je veux tout effacer');
            $this->fail('Test mode must be refused for a tenant user.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mode', $exception->errors());
        }
        $this->assertSame('protected', $real->fresh()->fiscal_data_mode);
    }

    public function test_fiscal_mode_endpoint_and_page_state(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->get('/settings/data-reset')->assertOk()
            ->assertInertia(fn ($page) => $page->where('fiscal.mode', 'protected')->where('fiscal.can_declare_test', false)->where('fiscal.protected_total', 0));

        $this->withFreshAuthentication(level: 2);
        $this->actingAs($owner)->postJson('/settings/data-reset/fiscal-mode', ['mode' => 'test', 'reason' => ''])->assertUnprocessable();
        $this->actingAs($owner)->postJson('/settings/data-reset/fiscal-mode', ['mode' => 'test', 'reason' => 'Démo', 'organization_id' => 999])->assertUnprocessable();
        $this->actingAs($owner)->postJson('/settings/data-reset/fiscal-mode', ['mode' => 'test', 'reason' => 'Démo'])->assertUnprocessable();
        $this->assertSame('protected', $organization->fresh()->fiscal_data_mode, 'The tenant UI can never lift protection.');

        // Legacy test organization: the Owner can still turn protection ON.
        $organization->forceFill(['fiscal_data_mode' => 'test'])->save();
        $this->actingAs($owner)->postJson('/settings/data-reset/fiscal-mode', ['mode' => 'protected', 'reason' => 'Go live'])
            ->assertOk()->assertJsonPath('data.mode', 'protected');

        $stranger = User::factory()->create();
        $this->createOrganization($stranger, 'Other');
        DB::table('organizations')->where('id', $organization->id)->update(['fiscal_data_mode' => 'test']);
        $this->actingAs($stranger)->postJson('/settings/data-reset/fiscal-mode', ['mode' => 'protected', 'reason' => 'x']);
        $this->assertSame('test', $organization->fresh()->fiscal_data_mode, 'Another tenant can never change this organization.');
    }

    public function test_test_mode_rows_are_never_protected_fiscal_documents_and_production_rows_stay_protected(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $fiscal = app(\App\Services\Finance\FiscalDocumentProtection::class);
        $this->assertSame(1, $fiscal->protectedCounts($organization)['invoices']);

        // The same row re-labelled as a Test Mode document is owned by the Sandbox, not protected here.
        $sandboxId = DB::table('sandboxes')->insertGetId(['organization_id' => $organization->id, 'name' => 'Test', 'status' => 'active', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('invoices')->where('id', $invoice->id)->update(['sandbox_id' => $sandboxId]);
        $this->assertSame(0, $fiscal->protectedCounts($organization)['invoices']);

        DB::table('invoices')->where('id', $invoice->id)->update(['sandbox_id' => null]);
        $this->assertSame(1, $fiscal->protectedCounts($organization)['invoices']);
    }

    public function test_platform_command_requires_force_to_reclassify_real_documents(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $this->artisan('organization:fiscal-data-mode', ['organization' => $organization->id, 'mode' => 'test', '--reason' => 'Démo'])->assertFailed();
        $this->assertSame('protected', $organization->fresh()->fiscal_data_mode);

        $this->artisan('organization:fiscal-data-mode', ['organization' => $organization->id, 'mode' => 'test', '--reason' => 'Organisation de démonstration commerciale', '--force' => true])->assertSuccessful();
        $this->assertSame('test', $organization->fresh()->fiscal_data_mode);
        $this->assertSame(1, DB::table('audit_logs')->where('organization_id', $organization->id)->where('event', 'organization.fiscal_data_mode_changed')->count());
    }

    public function test_numbering_is_never_reused_after_cleaning_test_data(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '300.0000');
        $organization->forceFill(['fiscal_data_mode' => 'test'])->save();
        $account = $this->createFinancialAccount($organization);
        $first = $this->recordPayment($owner, $order, $account, '100.0000');
        DB::table('payment_allocations')->where('payment_id', $first->id)->delete();
        DB::table('payments')->where('id', $first->id)->delete();

        $second = $this->recordPayment($owner, $order, $account, '100.0000');
        $this->assertNotSame($first->payment_number, $second->payment_number);
        $this->assertSame(0, Payment::query()->where('payment_number', $first->payment_number)->count());
    }

    private function selectivePlan(Organization $organization, array $data): SelectiveResetPlan
    {
        return app(SelectiveResetPlanner::class)->plan($organization->fresh(), SelectiveResetSelection::fromArray($data, app(SelectiveResetCategoryRegistry::class)));
    }
}
