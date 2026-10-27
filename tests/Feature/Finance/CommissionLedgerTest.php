<?php

namespace Tests\Feature\Finance;

use App\Actions\Commissions\GenerateSalesOrderCommissionEntriesAction;
use App\Actions\OrganizationBackups\RestoreOrganizationBackupAction;
use App\Actions\Returns\ReceiveCustomerReturnAction;
use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\StartSalesOrderCorrectionAction;
use App\Models\CommissionEntry;
use App\Models\CommissionRuleSet;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnLine;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Commissions\CommissionDashboardService;
use App\Services\Commissions\CommissionReconciliationService;
use App\Services\Commissions\CommissionReportFilters;
use App\Services\Commissions\CommissionRuleManager;
use App\Services\OrganizationBackups\OrganizationBackupSchema;
use App\Services\OrganizationBackups\OrganizationBackupExporter;
use App\Services\PermissionProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PosTestCase;

class CommissionLedgerTest extends PosTestCase
{
    public function test_confirmed_eligible_line_creates_one_immutable_snapshotted_entry(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockTenant('40', '100');
        $rule = $this->activateRule($owner, $organization, 'V1', '2026-01-01', '10');
        $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-08-10']);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2']);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);

        $entry = CommissionEntry::query()->sole();
        $this->assertSame(CommissionEntry::TYPE_SALE, $entry->entry_type);
        $this->assertSame('pending', $entry->status);
        $this->assertSame('200.0000', $entry->revenue_ht_snapshot);
        $this->assertSame('80.0000', $entry->cost_total_snapshot);
        $this->assertSame('120.0000', $entry->margin_amount_snapshot);
        $this->assertSame('60.0000', $entry->margin_rate_snapshot);
        $this->assertSame('10.0000', $entry->commission_rate_snapshot);
        $this->assertSame('12.0000', $entry->commission_amount);
        $this->assertSame($rule->id, $entry->commission_rule_set_id);
        $this->assertSame('V1', $entry->commission_rule_set_name_snapshot);
        $this->assertSame($owner->id, $entry->salesperson_id);
        $this->assertSame($owner->name, $entry->salesperson_name_snapshot);

        $variant->purchase_price = '99'; $variant->save();
        $v2 = $this->draftRule($owner, $organization, 'V2', '2026-09-01', '50');
        app(CommissionRuleManager::class)->activate($owner, $v2);
        $this->assertSame('12.0000', $entry->fresh()->commission_amount);
        $this->assertSame('40.0000', $entry->fresh()->purchase_cost_snapshot);
    }

    public function test_generation_is_idempotent_for_confirmation_and_reconciliation_retries(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockTenant();
        $this->activateRule($owner, $organization);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();

        DB::transaction(function () use ($owner, $order) {
            $first = app(GenerateSalesOrderCommissionEntriesAction::class)->execute($owner, $order);
            $second = app(GenerateSalesOrderCommissionEntriesAction::class)->execute($owner, $order);
            $this->assertSame(0, $first['created']);
            $this->assertSame(1, $second['existing']);
        });
        $this->assertDatabaseCount('commission_entries', 1);
    }

    public function test_missing_cost_salesperson_or_historical_rule_creates_no_fake_entry(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockTenant(null, '100');
        $this->activateRule($owner, $organization);
        $missingCost = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $missingCost, $variant, $warehouse);
        app(ConfirmSalesOrderAction::class)->execute($owner, $missingCost);

        $costed = $this->createProduct($organization, 'Other', null, ['purchase_price' => '40', 'default_sale_price' => '100'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $costed, '5');
        $missingPerson = $this->createDraftOrder($owner, $organization, $store);
        $missingPerson->salesperson_id = null; $missingPerson->salesperson_name_snapshot = null; $missingPerson->save();
        $this->addCatalogLine($owner, $missingPerson, $costed, $warehouse);
        app(ConfirmSalesOrderAction::class)->execute($owner, $missingPerson);

        $this->assertDatabaseCount('commission_entries', 0);
        $this->assertSame(1, app(CommissionReconciliationService::class)->scan($organization, '2026-08-01', '2026-08-31')['counts']['missing_cost']);
        $this->assertSame(1, app(CommissionReconciliationService::class)->scan($organization, '2026-08-01', '2026-08-31')['counts']['missing_salesperson']);

        [$ownerB, $organizationB, $storeB, $warehouseB, $variantB] = $this->stockTenant();
        $withoutRule = $this->createDraftOrder($ownerB, $organizationB, $storeB);
        $this->addCatalogLine($ownerB, $withoutRule, $variantB, $warehouseB);
        app(ConfirmSalesOrderAction::class)->execute($ownerB, $withoutRule);
        $this->assertSame(1, app(CommissionReconciliationService::class)->scan($organizationB, '2026-08-01', '2026-08-31')['counts']['no_rule_set']);
    }

    public function test_partial_and_full_returns_append_original_rule_and_salesperson_reversals_once(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockTenant('40', '100');
        $rule = $this->activateRule($owner, $organization, 'Original', '2026-01-01', '10');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $source = $order->lines()->sole();

        $first = $this->makeReturn($order, $source, $warehouse, $variant, '1', 'RET-COM-1');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $first);
        app(ReceiveCustomerReturnAction::class)->execute($owner, $first->fresh());
        $reversal = CommissionEntry::query()->where('entry_type', CommissionEntry::TYPE_RETURN_REVERSAL)->sole();
        $this->assertSame('-6.0000', $reversal->commission_amount);
        $this->assertSame('-60.0000', $reversal->margin_amount_snapshot);
        $this->assertSame($owner->id, $reversal->salesperson_id);
        $this->assertSame($rule->id, $reversal->commission_rule_set_id);

        $second = $this->makeReturn($order, $source, $warehouse, $variant, '1', 'RET-COM-2');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $second);
        $this->assertSame('0.0000', CommissionEntry::query()->get()->reduce(fn ($sum, $entry) => \App\Support\Decimal::add($sum, $entry->commission_amount), '0.0000'));
        $this->assertDatabaseCount('commission_entries', 3);
    }

    public function test_return_reversal_quantity_cannot_exceed_original_commissioned_quantity(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockTenant();
        $this->activateRule($owner, $organization);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $return = $this->makeReturn($order, $order->lines()->sole(), $warehouse, $variant, '2', 'RET-TOO-MUCH');

        $this->expectException(ValidationException::class);
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
    }

    public function test_correction_and_cancellation_append_adjustments_and_preserve_originals(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockTenant();
        $this->activateRule($owner, $organization);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $originalId = CommissionEntry::query()->sole()->id;

        app(StartSalesOrderCorrectionAction::class)->execute($owner, $order, 'Correction test');
        $this->assertDatabaseHas('commission_entries', ['source_entry_id' => $originalId, 'entry_type' => CommissionEntry::TYPE_CORRECTION]);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh())->fresh();
        $this->assertSame(2, CommissionEntry::query()->where('entry_type', CommissionEntry::TYPE_SALE)->count());

        $current = CommissionEntry::query()->where('entry_type', CommissionEntry::TYPE_SALE)->latest('id')->firstOrFail();
        app(CancelSalesOrderAction::class)->execute($owner, $order, 'Annulation test');
        $this->assertDatabaseHas('commission_entries', ['source_entry_id' => $current->id, 'entry_type' => CommissionEntry::TYPE_CANCELLATION]);
        $this->assertDatabaseHas('commission_entries', ['id' => $originalId, 'entry_type' => CommissionEntry::TYPE_SALE]);
    }

    public function test_approval_and_paid_lifecycle_is_authorized_atomic_and_fresh_authenticated(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockTenant();
        $this->activateRule($owner, $organization);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $entry = CommissionEntry::query()->sole();

        $this->actingAs($owner)->post(route('finance.commissions.ledger.approve'), ['entry_ids' => [$entry->id]])->assertRedirect();
        $entry = $entry->fresh();
        $this->assertSame('approved', $entry->status);
        $this->assertSame($owner->id, $entry->approved_by_user_id);
        $this->assertNotNull($entry->approved_at);
        $this->actingAs($owner)->post(route('finance.commissions.ledger.mark-paid'), ['entry_ids' => [$entry->id]])->assertRedirect(route('security.confirm'));
        $this->withFreshAuthentication(1)->actingAs($owner)->post(route('finance.commissions.ledger.mark-paid'), ['entry_ids' => [$entry->id]])->assertRedirect();
        $this->assertSame('paid', $entry->fresh()->status);
        $this->assertSame($owner->id, $entry->fresh()->paid_by_user_id);

        $this->withFreshAuthentication(1)->actingAs($owner)->post(route('finance.commissions.ledger.mark-paid'), ['entry_ids' => [$entry->id]])->assertSessionHasErrors('entries');
    }

    public function test_explicit_reconciliation_generates_historical_eligible_entry_only_once(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockTenant();
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $this->assertDatabaseCount('commission_entries', 0);
        $this->activateRule($owner, $organization, 'Backdated', '2026-01-01');
        $this->assertSame(1, app(CommissionReconciliationService::class)->scan($organization, '2026-08-01', '2026-08-31')['counts']['eligible_missing']);

        $payload = ['from' => '2026-08-01', 'to' => '2026-08-31'];
        $this->actingAs($owner)->post(route('finance.commissions.ledger.reconcile'), $payload)->assertRedirect(route('security.confirm'));
        $this->withFreshAuthentication(1)->actingAs($owner)->post(route('finance.commissions.ledger.reconcile'), $payload)->assertRedirect();
        $this->withFreshAuthentication(1)->actingAs($owner)->post(route('finance.commissions.ledger.reconcile'), $payload)->assertRedirect();
        $this->assertDatabaseCount('commission_entries', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'commission.reconciliation_run']);
    }

    public function test_reconciliation_handles_received_returns_outside_the_active_store(): void
    {
        [$owner, $organization, $activeStore] = $this->tenant(true);
        $otherStore = $this->createStore($organization, $owner, 'Other store');
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Cross-store product', null, ['purchase_price' => '40', 'default_sale_price' => '100'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '5');
        $this->activateRule($owner, $organization);
        $this->activate($owner, $organization, $otherStore);
        $order = $this->createDraftOrder($owner, $organization, $otherStore);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $return = $this->makeReturn($order, $order->lines()->sole(), $warehouse, $variant, '1', 'RET-OTHER-STORE');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        DB::table('commission_entries')->where('entry_type', CommissionEntry::TYPE_RETURN_REVERSAL)->delete();
        $this->activate($owner, $organization, $activeStore);

        $scan = app(CommissionReconciliationService::class)->scan($organization, '2026-08-01', '2026-08-31');
        $this->assertSame(1, $scan['counts']['missing_return_reversal']);
        $result = app(CommissionReconciliationService::class)->generate($owner, $organization, '2026-08-01', '2026-08-31');

        $this->assertSame(1, $result['reversals_created']);
        $this->assertDatabaseHas('commission_entries', ['customer_return_id' => $return->id, 'entry_type' => CommissionEntry::TYPE_RETURN_REVERSAL]);
    }

    public function test_ledger_permissions_and_tenant_isolation_are_enforced(): void
    {
        [$ownerA, $organizationA, $storeA, $warehouseA, $variantA] = $this->stockTenant();
        $this->activateRule($ownerA, $organizationA);
        $order = $this->createDraftOrder($ownerA, $organizationA, $storeA); $this->addCatalogLine($ownerA, $order, $variantA, $warehouseA); app(ConfirmSalesOrderAction::class)->execute($ownerA, $order);
        $entry = CommissionEntry::query()->sole();
        $sales = User::factory()->create(); $this->addDefaultSalesEmployee($organizationA, $storeA, $sales);
        $this->actingAs($sales)->get(route('finance.commissions.ledger.index'))->assertForbidden();
        $this->actingAs($sales)->post(route('finance.commissions.ledger.approve'), ['entry_ids' => [$entry->id]])->assertForbidden();

        [$ownerB, $organizationB] = $this->tenant();
        $this->activate($ownerB, $organizationB);
        $this->actingAs($ownerB)->get(route('finance.commissions.ledger.show', $entry))->assertNotFound();
        $this->actingAs($ownerB)->post(route('finance.commissions.ledger.approve'), ['entry_ids' => [$entry->id]])->assertNotFound();
    }

    public function test_permission_provisioning_and_backup_allowlist_include_ledger_without_custom_role_escalation(): void
    {
        [$owner, $organization] = $this->tenant();
        $custom = $this->createRole($organization, [], 'No ledger');
        app(PermissionProvisioner::class)->provisionOrganization($organization);
        $admin = $organization->roles()->where('slug', 'admin')->firstOrFail();
        $this->assertTrue($admin->permissions()->where('key', 'commissions.ledger.mark_paid')->exists());
        $this->assertFalse($custom->permissions()->where('key', 'commissions.ledger.view')->exists());
        $this->assertContains('commissions.ledger.reconcile', config('platform.presets.finance.permissions'));
        $this->assertContains('commission_entries', app(OrganizationBackupSchema::class)->tenantTables());
    }

    public function test_commission_ledger_snapshots_and_lifecycle_are_restored_from_organization_backup(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockTenant();
        $this->activateRule($owner, $organization);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $entry = CommissionEntry::query()->sole();
        $this->actingAs($owner)->post(route('finance.commissions.ledger.approve'), ['entry_ids' => [$entry->id]])->assertRedirect();
        $entry = $entry->fresh();

        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        DB::table('commission_entries')->where('organization_id', $organization->id)->delete();
        $this->assertDatabaseMissing('commission_entries', ['id' => $entry->id]);

        app(RestoreOrganizationBackupAction::class)->execute($owner, $organization, $archive->path);

        $this->assertDatabaseHas('commission_entries', [
            'id' => $entry->id,
            'organization_id' => $organization->id,
            'status' => CommissionEntry::STATUS_APPROVED,
            'salesperson_name_snapshot' => $entry->salesperson_name_snapshot,
            'commission_amount' => $entry->commission_amount,
        ]);
    }

    public function test_zero_rate_tier_sale_is_kept_with_snapshots_as_no_commission(): void
    {
        // 200 revenue, 190 cost: 5% margin falls in the "< 10%" tier configured at 0%.
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockTenant('95', '100');
        $this->activateRule($owner, $organization);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2']);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);

        $entry = CommissionEntry::query()->sole();
        $this->assertSame(CommissionEntry::TYPE_SALE, $entry->entry_type);
        $this->assertSame(CommissionEntry::STATUS_NO_COMMISSION, $entry->status);
        $this->assertSame('200.0000', $entry->revenue_ht_snapshot);
        $this->assertSame('10.0000', $entry->margin_amount_snapshot);
        $this->assertSame('5.0000', $entry->margin_rate_snapshot);
        $this->assertSame('0.0000', $entry->commission_rate_snapshot);
        $this->assertSame('0.0000', $entry->commission_amount);
        $this->assertNull($entry->rule_min_margin_snapshot);
        $this->assertSame('10.0000', $entry->rule_max_margin_snapshot);
    }

    public function test_finance_register_lists_no_commission_entry_with_margin_columns_and_zero_payable_totals(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockTenant('95', '100');
        $this->activateRule($owner, $organization);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2']);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $entry = CommissionEntry::query()->sole();

        $page = fn (array $query = []) => $this->actingAs($owner)->get(route('finance.commissions.ledger.index', $query))->assertOk();
        $page()->assertInertia(fn (Assert $page) => $page->component('Finance/CommissionLedger')
            ->has('entries.data', 1)
            ->where('entries.data.0.id', $entry->id)
            ->where('entries.data.0.status', 'no_commission')
            ->where('entries.data.0.margin_amount_snapshot', '10.0000')
            ->where('entries.data.0.margin_percent', '5.0000')
            ->where('entries.data.0.commission_rate_snapshot', '0.0000')
            ->where('entries.data.0.commission_amount', '0.0000')
            ->where('summary.pending', '0.0000')
            ->where('summary.approved', '0.0000')
            ->where('summary.paid', '0.0000')
            ->where('summary.no_commission_count', 1)
            ->where('summary.entry_count', 1));
        $page(['status' => 'no_commission'])->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
        $page(['status' => 'pending'])->assertInertia(fn (Assert $page) => $page->has('entries.data', 0));
    }

    public function test_backend_refuses_to_approve_or_pay_a_no_commission_entry_even_in_a_mixed_batch(): void
    {
        [$owner, $organization, $store, $warehouse, $zeroVariant] = $this->stockTenant('95', '100');
        $paidVariant = $this->createProduct($organization, 'Payable product', null, ['purchase_price' => '40', 'default_sale_price' => '100'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $paidVariant, '30');
        $this->activateRule($owner, $organization);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $zeroVariant, $warehouse);
        $this->addCatalogLine($owner, $order, $paidVariant, $warehouse);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $zero = CommissionEntry::query()->where('status', CommissionEntry::STATUS_NO_COMMISSION)->sole();
        $payable = CommissionEntry::query()->where('status', CommissionEntry::STATUS_PENDING)->sole();

        $this->actingAs($owner)->post(route('finance.commissions.ledger.approve'), ['entry_ids' => [$zero->id]])->assertSessionHasErrors('entries');
        $this->actingAs($owner)->post(route('finance.commissions.ledger.approve'), ['entry_ids' => [$payable->id, $zero->id]])->assertSessionHasErrors('entries');
        $this->assertSame(CommissionEntry::STATUS_PENDING, $payable->fresh()->status, 'A batch containing a 0% entry is refused atomically.');
        $this->withFreshAuthentication(1)->actingAs($owner)->post(route('finance.commissions.ledger.mark-paid'), ['entry_ids' => [$zero->id]])->assertSessionHasErrors('entries');

        $zero = $zero->fresh();
        $this->assertSame(CommissionEntry::STATUS_NO_COMMISSION, $zero->status);
        $this->assertNull($zero->approved_at);
        $this->assertNull($zero->paid_at);
        $this->assertSame(0, UserNotification::query()->count());

        // Normal positive entry still runs pending → approved → paid.
        $this->actingAs($owner)->post(route('finance.commissions.ledger.approve'), ['entry_ids' => [$payable->id]])->assertSessionHasNoErrors();
        $this->assertSame(CommissionEntry::STATUS_APPROVED, $payable->fresh()->status);
        $this->withFreshAuthentication(1)->actingAs($owner)->post(route('finance.commissions.ledger.mark-paid'), ['entry_ids' => [$payable->id]])->assertSessionHasNoErrors();
        $this->assertSame(CommissionEntry::STATUS_PAID, $payable->fresh()->status);
    }

    public function test_dashboard_keeps_zero_rate_revenue_and_margin_but_excludes_it_from_payable_totals(): void
    {
        [$owner, $organization, $store, $warehouse, $zeroVariant] = $this->stockTenant('95', '100');
        $paidVariant = $this->createProduct($organization, 'Payable product', null, ['purchase_price' => '40', 'default_sale_price' => '100'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $paidVariant, '30');
        $this->activateRule($owner, $organization);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $zeroVariant, $warehouse);
        $this->addCatalogLine($owner, $order, $paidVariant, $warehouse);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);

        $service = app(CommissionDashboardService::class);
        $filters = new CommissionReportFilters('custom', now()->toDateString(), now()->toDateString());
        $kpis = $service->kpis($organization, $filters);
        // Revenue 100 + 100, margin 5 + 60; only the 60% line earns 10% = 6.
        $this->assertSame('200.0000', $kpis['revenue']);
        $this->assertSame('65.0000', $kpis['margin']);
        $this->assertSame('6.0000', $kpis['pending']);
        $this->assertSame('0.0000', $kpis['approved']);
        $this->assertSame('0.0000', $kpis['paid']);
        $this->assertSame(2, $kpis['entry_count']);

        $statuses = collect($service->statusBreakdown($organization, $filters))->keyBy('status');
        $this->assertSame(['count' => 1, 'amount' => '6.0000'], ['count' => $statuses['pending']['count'], 'amount' => $statuses['pending']['amount']]);
        $this->assertSame(1, $statuses['no_commission']['count']);
        $this->assertSame('0.0000', $statuses['no_commission']['amount']);

        $noCommissionOnly = new CommissionReportFilters('custom', now()->toDateString(), now()->toDateString(), status: CommissionEntry::STATUS_NO_COMMISSION);
        $kpis = $service->kpis($organization, $noCommissionOnly);
        $this->assertSame('100.0000', $kpis['revenue']);
        $this->assertSame('5.0000', $kpis['margin']);
        $this->assertSame('0.0000', $kpis['pending']);
        $this->assertSame('0.0000', $kpis['net']);
    }

    public function test_return_of_a_no_commission_sale_is_a_visible_non_payable_reversal(): void
    {
        [$owner, $organization, $store, $warehouse, $zeroVariant] = $this->stockTenant('95', '100');
        $paidVariant = $this->createProduct($organization, 'Payable product', null, ['purchase_price' => '40', 'default_sale_price' => '100'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $paidVariant, '30');
        $this->activateRule($owner, $organization);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $zeroLine = $this->addCatalogLine($owner, $order, $zeroVariant, $warehouse, ['quantity' => '2']);
        $paidLine = $this->addCatalogLine($owner, $order, $paidVariant, $warehouse, ['quantity' => '2']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $zeroSale = CommissionEntry::query()->where('sales_order_line_id', $zeroLine->id)->sole();
        $paidSale = CommissionEntry::query()->where('sales_order_line_id', $paidLine->id)->sole();
        $this->assertSame(CommissionEntry::STATUS_NO_COMMISSION, $zeroSale->status);
        $this->assertSame(CommissionEntry::STATUS_PENDING, $paidSale->status);

        app(ReceiveCustomerReturnAction::class)->execute($owner, $this->makeReturn($order, $zeroLine->fresh(), $warehouse, $zeroVariant, '1', 'RET-ZERO-1'));
        app(ReceiveCustomerReturnAction::class)->execute($owner, $this->makeReturn($order, $paidLine->fresh(), $warehouse, $paidVariant, '1', 'RET-PAID-1'));

        // Zero-rate reversal: same snapshots/type as before, but non-payable.
        $zeroReversal = CommissionEntry::query()->where('source_entry_id', $zeroSale->id)->sole();
        $this->assertSame(CommissionEntry::TYPE_RETURN_REVERSAL, $zeroReversal->entry_type);
        $this->assertSame(CommissionEntry::STATUS_NO_COMMISSION, $zeroReversal->status);
        $this->assertSame('-1.0000', $zeroReversal->quantity_snapshot);
        $this->assertSame('-100.0000', $zeroReversal->revenue_ht_snapshot);
        $this->assertSame('-5.0000', $zeroReversal->margin_amount_snapshot);
        $this->assertSame('0.0000', $zeroReversal->commission_rate_snapshot);
        $this->assertSame('0.0000', $zeroReversal->commission_amount);

        // Payable reversal: unchanged behaviour.
        $paidReversal = CommissionEntry::query()->where('source_entry_id', $paidSale->id)->sole();
        $this->assertSame(CommissionEntry::TYPE_RETURN_REVERSAL, $paidReversal->entry_type);
        $this->assertSame(CommissionEntry::STATUS_PENDING, $paidReversal->status);
        $this->assertSame('-6.0000', $paidReversal->commission_amount);

        $this->actingAs($owner)->get(route('finance.commissions.ledger.index', ['status' => 'no_commission']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 2)
                ->where('entries.data.0.id', $zeroReversal->id)
                ->where('entries.data.0.entry_type', CommissionEntry::TYPE_RETURN_REVERSAL)
                ->where('summary.no_commission_count', 2));

        $this->actingAs($owner)->post(route('finance.commissions.ledger.approve'), ['entry_ids' => [$zeroReversal->id]])->assertSessionHasErrors('entries');
        $this->withFreshAuthentication(1)->actingAs($owner)->post(route('finance.commissions.ledger.mark-paid'), ['entry_ids' => [$zeroReversal->id]])->assertSessionHasErrors('entries');
        $this->assertSame(CommissionEntry::STATUS_NO_COMMISSION, $zeroReversal->fresh()->status);

        $this->actingAs($owner)->post(route('finance.commissions.ledger.approve'), ['entry_ids' => [$paidReversal->id]])->assertSessionHasNoErrors();
        $this->assertSame(CommissionEntry::STATUS_APPROVED, $paidReversal->fresh()->status);
    }

    public function test_cancellation_of_a_no_commission_sale_appends_a_non_payable_reversal(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockTenant('95', '100');
        $this->activateRule($owner, $organization);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $sale = CommissionEntry::query()->sole();

        app(CancelSalesOrderAction::class)->execute($owner, $order, 'Annulation test');

        $cancellation = CommissionEntry::query()->where('source_entry_id', $sale->id)->sole();
        $this->assertSame(CommissionEntry::TYPE_CANCELLATION, $cancellation->entry_type);
        $this->assertSame(CommissionEntry::STATUS_NO_COMMISSION, $cancellation->status);
        $this->assertSame('-1.0000', $cancellation->quantity_snapshot);
        $this->assertSame('0.0000', $cancellation->commission_amount);
        $this->assertSame(CommissionEntry::STATUS_NO_COMMISSION, $sale->fresh()->status);
    }

    public function test_backfill_relabels_only_unambiguous_zero_rate_sales_and_their_zero_reversals(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockTenant();
        $this->activateRule($owner, $organization);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $template = (array) DB::table('commission_entries')->sole();
        unset($template['id']);
        $insert = function (string $key, array $overrides) use ($template) {
            return DB::table('commission_entries')->insertGetId(array_replace($template, ['source_key' => 'backfill:'.$key], $overrides));
        };
        $zero = ['commission_rate_snapshot' => '0', 'commission_amount' => '0'];
        $ids = [
            'sale_pending' => $insert('sale_pending', $zero + ['status' => 'pending']),
            'sale_approved' => $insert('sale_approved', $zero + ['status' => 'approved', 'approved_by_user_id' => $owner->id, 'approved_at' => now()]),
            'sale_paid' => $insert('sale_paid', $zero + ['status' => 'paid', 'paid_at' => now()]),
            'sale_positive_rate_zero_amount' => $insert('positive_rate', ['commission_rate_snapshot' => '5', 'commission_amount' => '0', 'status' => 'pending']),
            'return_reversal' => $insert('return', $zero + ['entry_type' => 'return_reversal', 'status' => 'pending']),
            'correction' => $insert('correction', $zero + ['entry_type' => 'correction', 'status' => 'pending']),
            'cancellation' => $insert('cancellation', $zero + ['entry_type' => 'cancellation', 'status' => 'pending']),
            'manual_adjustment' => $insert('manual', $zero + ['entry_type' => 'manual_adjustment', 'status' => 'pending']),
        ];
        $positive = CommissionEntry::query()->where('source_key', $template['source_key'])->sole();
        // Reversals linked by source_entry_id (the only relationship the backfill follows).
        $linked = fn (string $type, int $source, array $overrides = []) => array_replace($zero, [
            'entry_type' => $type, 'source_entry_id' => $source, 'status' => 'pending',
            'quantity_snapshot' => '-1', 'revenue_ht_snapshot' => '-100', 'cost_total_snapshot' => '-95', 'margin_amount_snapshot' => '-5',
        ], $overrides);
        $ids += [
            'zero_return' => $insert('zero_return', $linked('return_reversal', $ids['sale_pending'])),
            'zero_cancellation' => $insert('zero_cancellation', $linked('cancellation', $ids['sale_pending'], ['status' => 'approved', 'approved_by_user_id' => $owner->id, 'approved_at' => now()])),
            'zero_correction' => $insert('zero_correction', $linked('correction', $ids['sale_approved'])),
            'zero_return_paid' => $insert('zero_return_paid', $linked('return_reversal', $ids['sale_pending'], ['status' => 'paid', 'paid_at' => now()])),
            'zero_manual_linked' => $insert('zero_manual_linked', $linked('manual_adjustment', $ids['sale_pending'])),
            'paid_sale_return' => $insert('paid_sale_return', $linked('return_reversal', $ids['sale_paid'])),
            'payable_return' => $insert('payable_return', $linked('return_reversal', $positive->id, ['commission_rate_snapshot' => '10', 'commission_amount' => '-6'])),
            'payable_zero_amount_return' => $insert('payable_zero_return', $linked('return_reversal', $positive->id, ['commission_rate_snapshot' => '10'])),
        ];
        $before = DB::table('commission_entries')->orderBy('id')->get()->keyBy('id');

        $migration = require database_path('migrations/2026_10_27_000002_backfill_commission_entries_no_commission_status.php');
        $migration->up();
        $migration->up();

        $status = fn (int $id) => DB::table('commission_entries')->where('id', $id)->value('status');
        $this->assertSame('no_commission', $status($ids['sale_pending']));
        $this->assertSame('no_commission', $status($ids['sale_approved']));
        $this->assertNotNull(DB::table('commission_entries')->where('id', $ids['sale_approved'])->value('approved_at'), 'Approval audit trail is kept.');
        $this->assertSame('paid', $status($ids['sale_paid']));
        $this->assertSame('pending', $status($ids['sale_positive_rate_zero_amount']));
        foreach (['return_reversal', 'correction', 'cancellation', 'manual_adjustment'] as $untouched) {
            $this->assertSame('pending', $status($ids[$untouched]), $untouched);
        }
        $this->assertSame('pending', $positive->fresh()->status);
        // Zero-amount reversals of a now-no_commission sale follow it; nothing else does.
        $this->assertSame('no_commission', $status($ids['zero_return']));
        $this->assertSame('no_commission', $status($ids['zero_cancellation']));
        $this->assertSame('no_commission', $status($ids['zero_correction']));
        $this->assertSame('paid', $status($ids['zero_return_paid']));
        $this->assertSame('pending', $status($ids['zero_manual_linked']));
        $this->assertSame('pending', $status($ids['paid_sale_return']));
        $this->assertSame('pending', $status($ids['payable_return']));
        $this->assertSame('pending', $status($ids['payable_zero_amount_return']));
        foreach (DB::table('commission_entries')->get() as $row) {
            $original = $before[$row->id];
            foreach (['quantity_snapshot', 'revenue_ht_snapshot', 'cost_total_snapshot', 'margin_amount_snapshot', 'commission_rate_snapshot', 'commission_amount', 'entry_type', 'source_entry_id', 'approved_at', 'paid_at', 'organization_id'] as $column) {
                $this->assertEquals($original->{$column}, $row->{$column}, "{$column} of entry {$row->id} must not change");
            }
        }

        $migration->down();
        foreach ($before as $id => $original) {
            $this->assertSame($original->status, $status($id), "down() restores entry {$id}");
        }
    }

    private function activateRule(User $owner, $organization, string $name = 'Commission', string $from = '2026-01-01', string $topRate = '10'): CommissionRuleSet
    {
        $set = $this->draftRule($owner, $organization, $name, $from, $topRate);
        return app(CommissionRuleManager::class)->activate($owner, $set);
    }

    private function draftRule(User $owner, $organization, string $name, string $from, string $topRate): CommissionRuleSet
    {
        return app(CommissionRuleManager::class)->create($owner, $organization, ['name' => $name, 'effective_from' => $from], [
            ['min_margin_rate' => null, 'max_margin_rate' => '10', 'commission_rate' => '0'],
            ['min_margin_rate' => '10', 'max_margin_rate' => '30', 'commission_rate' => '5'],
            ['min_margin_rate' => '30', 'max_margin_rate' => null, 'commission_rate' => $topRate],
        ]);
    }

    private function stockTenant(?string $cost = '40', string $price = '100'): array
    {
        [$owner, $organization, $store] = $this->tenant(true);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Commission product', null, ['purchase_price' => $cost, 'default_sale_price' => $price])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '30');
        return [$owner, $organization, $store, $warehouse, $variant];
    }

    private function tenant(bool $store = false): array
    {
        $owner = User::factory()->create(); $organization = $this->createOrganization($owner);
        $createdStore = $store ? $this->createStore($organization, $owner) : null; $this->activate($owner, $organization, $createdStore);
        return $store ? [$owner, $organization, $createdStore] : [$owner, $organization];
    }

    private function makeReturn($order, $source, $warehouse, $variant, string $quantity, string $number): CustomerReturn
    {
        $return = new CustomerReturn;
        $return->organization_id = $order->organization_id; $return->store_id = $order->store_id; $return->sales_order_id = $order->id; $return->warehouse_id = $warehouse->id;
        $return->return_number = $number; $return->client_operation_id = (string) Str::uuid(); $return->status = 'draft'; $return->disposition = 'restock'; $return->reason = 'Test commission';
        $return->currency_code = 'MAD'; $return->subtotal_excl_tax = \App\Support\Decimal::multiply('100', $quantity); $return->discount_total = '0'; $return->tax_total = '0'; $return->total_incl_tax = $return->subtotal_excl_tax;
        $return->policy_snapshot = []; $return->save();
        $line = new CustomerReturnLine;
        $line->organization_id = $order->organization_id; $line->customer_return_id = $return->id; $line->sales_order_line_id = $source->id; $line->product_variant_id = $variant->id; $line->position = 1;
        foreach (['product_name', 'variant_name', 'sku', 'reference', 'unit_label', 'unit_price_excl_tax', 'unit_price_incl_tax', 'tax_name', 'tax_rate'] as $field) $line->{$field} = $source->{$field};
        $line->quantity = $quantity; $line->subtotal_excl_tax = $return->subtotal_excl_tax; $line->discount_amount = '0'; $line->taxable_amount = $return->subtotal_excl_tax; $line->tax_amount = '0'; $line->total_incl_tax = $return->subtotal_excl_tax; $line->save();
        return $return;
    }
}
