<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceObligationAction;
use App\Models\FinanceObligation;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierInvoiceInstallment;
use App\Models\User;
use App\Services\Finance\FinanceMonthlySituationService;
use App\Services\Finance\FinanceNumberGenerator;
use App\Services\Finance\FinancePeriod;
use App\Services\OrganizationBackups\OrganizationBackupExporter;
use App\Services\OrganizationBackups\OrganizationBackupRestorer;
use App\Services\OrganizationDataReset\OrganizationDataResetBundleRegistry;
use App\Services\OrganizationDataReset\OrganizationDataResetDependencyGraph;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use App\Services\SupplierInvoices\SupplierInvoiceObligationScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\Support\SupplierInvoiceTestCase;

/** Phase 2 Sprint 1 (PR7): obligation link to supplier invoice installments, model guard and read exposure. */
class SupplierInvoiceObligationGuardTest extends SupplierInvoiceTestCase
{
    /** @return array{User, Organization, Store, SupplierInvoiceInstallment, FinanceObligation} */
    private function linked(string $dueDate = '2026-12-20'): array
    {
        [$owner, $organization, $store, $supplier] = $this->invoiceFixture();
        $invoice = $this->makeInvoice($organization, $store, $supplier, ['invoice_number' => 'F-LINK']);
        $installment = $this->makeInstallment($invoice);
        $this->forceApproved($invoice);
        $obligation = $this->makeObligation($organization, $store->id, $supplier->id, $installment->id, $dueDate);

        return [$owner, $organization, $store, $installment, $obligation];
    }

    private function makeObligation(Organization $organization, ?int $storeId, ?int $supplierId, ?int $installmentId, string $dueDate = '2026-12-20'): FinanceObligation
    {
        return SupplierInvoiceObligationScope::run(function () use ($organization, $storeId, $supplierId, $installmentId, $dueDate) {
            $o = new FinanceObligation;
            $o->organization_id = $organization->id;
            $o->store_id = $storeId;
            $o->obligation_number = DB::transaction(fn () => app(FinanceNumberGenerator::class)->nextObligation($organization, 2026));
            $o->category = 'supplier';
            $o->label = 'Facture F-LINK 1/1';
            $o->supplier_id = $supplierId;
            $o->due_date = $dueDate;
            $o->planned_amount = '120.0000';
            $o->currency_code = 'MAD';
            $o->supplier_invoice_installment_id = $installmentId;
            $o->save();

            return $o->fresh();
        });
    }

    public function test_installment_obligation_relation_and_through_relation_work(): void
    {
        [, , , $installment, $obligation] = $this->linked();

        $this->assertSame($obligation->id, $installment->obligation->id);
        $this->assertSame($installment->id, $obligation->supplierInvoiceInstallment->id);
        $this->assertSame('F-LINK', $obligation->supplierInvoice->invoice_number);
    }

    public function test_one_obligation_per_installment_and_exclusivity_checks_at_database_level(): void
    {
        [, $organization, $store, $installment, $obligation] = $this->linked();
        $row = (array) DB::table('finance_obligations')->where('id', $obligation->id)->first();
        unset($row['id']);

        $duplicate = $row;
        $duplicate['obligation_number'] = 'OBL-DUP-1';
        $this->assertRejected(fn () => DB::table('finance_obligations')->insert($duplicate), 'unique installment link');

        $other = $this->makeInstallment($this->makeInvoice($organization, $store, Supplier::query()->where('organization_id', $organization->id)->firstOrFail()));
        $plan = $row;
        $plan['obligation_number'] = 'OBL-DUP-2';
        $plan['supplier_invoice_installment_id'] = $other->id;
        $plan['supplier_installment_plan_id'] = 999999;
        $this->assertRejected(fn () => DB::table('finance_obligations')->insert($plan), 'plan exclusivity');

        $recurring = $row;
        $recurring['obligation_number'] = 'OBL-DUP-3';
        $recurring['supplier_invoice_installment_id'] = $other->id;
        $recurring['recurring_template_id'] = 999999;
        $this->assertRejected(fn () => DB::table('finance_obligations')->insert($recurring), 'recurring exclusivity');

        $legacy = $this->makeObligation($organization, $store->id, null, null);
        $this->assertRejected(fn () => DB::table('finance_obligations')->where('id', $legacy->id)->update(['supplier_invoice_installment_id' => $other->id, 'recurring_template_id' => 999999]), 'update exclusivity');
        $this->assertNull($legacy->fresh()->supplier_invoice_installment_id);
    }

    public function test_foreign_key_is_tenant_composite_and_restricts_installment_deletion(): void
    {
        [, , , $installment, $obligation] = $this->linked();
        [, $otherOrg, $otherStore] = $this->invoiceFixture('Other');

        $this->assertRejected(fn () => DB::table('finance_obligations')->where('id', $obligation->id)->update(['organization_id' => $otherOrg->id, 'store_id' => $otherStore->id]), 'cross-tenant link');
        $this->assertRejected(fn () => DB::table('supplier_invoice_installments')->where('id', $installment->id)->delete(), 'restrict delete');
    }

    public function test_model_guard_blocks_each_guarded_field_outside_the_scope(): void
    {
        [, , , , $obligation] = $this->linked();
        $changes = ['planned_amount' => '1.0000', 'due_date' => '2027-01-01', 'currency_code' => 'EUR', 'category' => 'rent',
            'cancelled_at' => now(), 'cancelled_by_user_id' => null, 'cancellation_reason' => 'x', 'supplier_id' => null, 'store_id' => null,
            'supplier_invoice_installment_id' => null];
        foreach ($changes as $field => $value) {
            $fresh = FinanceObligation::query()->whereKey($obligation->id)->firstOrFail();
            $fresh->{$field} = $value;
            if (! $fresh->isDirty($field)) {
                continue; // value equals the stored one (e.g. cancelled_by_user_id stays null): nothing to guard
            }
            try {
                $fresh->save();
                $this->fail("Guard did not block {$field}");
            } catch (LogicException) {
                $this->assertNotNull(DB::table('finance_obligations')->where('id', $obligation->id)->value('supplier_invoice_installment_id'), $field);
            }
        }
        $this->assertSame('120.0000', (string) $obligation->fresh()->planned_amount);
        $this->assertNull($obligation->fresh()->cancelled_at);
    }

    public function test_model_guard_blocks_delete_and_allows_free_fields_and_the_sanctioned_scope(): void
    {
        [, , , , $obligation] = $this->linked();

        $fresh = FinanceObligation::query()->whereKey($obligation->id)->firstOrFail();
        try {
            $fresh->delete();
            $this->fail('Delete must be blocked');
        } catch (LogicException) {
            $this->assertNotNull(FinanceObligation::query()->find($obligation->id));
        }

        $fresh->notes = 'Note libre';
        $fresh->save();
        $this->assertSame('Note libre', $obligation->fresh()->notes);

        $this->assertFalse(SupplierInvoiceObligationScope::active());
        SupplierInvoiceObligationScope::run(function () use ($obligation) {
            $this->assertTrue(SupplierInvoiceObligationScope::active());
            $row = FinanceObligation::query()->whereKey($obligation->id)->firstOrFail();
            $row->cancelled_at = now();
            $row->cancellation_reason = 'Facture fournisseur annulée : test';
            $row->save();
        });
        $this->assertFalse(SupplierInvoiceObligationScope::active());
        $this->assertNotNull($obligation->fresh()->cancelled_at);

        try {
            SupplierInvoiceObligationScope::run(fn () => SupplierInvoiceObligationScope::run(fn () => throw new RuntimeException('boom')));
        } catch (RuntimeException) {
            // expected
        }
        $this->assertFalse(SupplierInvoiceObligationScope::active());
    }

    public function test_legacy_obligations_are_not_guarded(): void
    {
        [, $organization, $store] = $this->linked();
        $legacy = $this->makeObligation($organization, $store->id, null, null);
        $legacy->planned_amount = '5.0000';
        $legacy->save();
        $this->assertSame('5.0000', (string) $legacy->fresh()->planned_amount);
        $this->assertNull($legacy->fresh()->supplier_invoice_installment_id);
        $legacy->delete();
        $this->assertNull(FinanceObligation::query()->find($legacy->id));
    }

    public function test_manage_action_refuses_update_and_cancel_of_invoice_obligations(): void
    {
        [$owner, , , , $obligation] = $this->linked();
        $action = app(ManageFinanceObligationAction::class);
        $data = ['store_id' => $obligation->store_id, 'category' => 'supplier', 'label' => 'Autre', 'supplier_id' => $obligation->supplier_id, 'due_date' => '2026-12-20', 'planned_amount' => '50.0000', 'currency_code' => 'MAD'];

        foreach ([fn () => $action->update($owner, $obligation, $data), fn () => $action->cancel($owner, $obligation, 'Motif')] as $call) {
            try {
                $call();
                $this->fail('Expected refusal');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('facture fournisseur', $e->errors()['obligation'][0]);
            }
        }
        $this->assertSame('120.0000', (string) $obligation->fresh()->planned_amount);
        $this->assertNull($obligation->fresh()->cancelled_at);
    }

    public function test_manage_action_still_updates_and_cancels_legacy_obligations(): void
    {
        [$owner, $organization] = $this->linked();
        $action = app(ManageFinanceObligationAction::class);
        $base = ['store_id' => null, 'category' => 'rent', 'label' => 'Loyer', 'due_date' => '2026-12-15', 'planned_amount' => '100.0000', 'currency_code' => 'MAD'];
        $legacy = $action->create($owner, $organization, $base);
        $updated = $action->update($owner, $legacy, array_replace($base, ['label' => 'Loyer 2', 'planned_amount' => '200.0000']));
        $this->assertSame('200.0000', (string) $updated->planned_amount);
        $action->cancel($owner, $updated, 'Test');
        $this->assertNotNull($legacy->fresh()->cancelled_at);
    }

    public function test_monthly_situation_exposes_the_invoice_link_without_n_plus_one_and_keeps_legacy_shape(): void
    {
        [, $organization, $store, $installment, $obligation] = $this->linked();
        $supplier = Supplier::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->makeObligation($organization, $store->id, null, null, '2026-12-22');
        for ($i = 2; $i <= 4; $i++) {
            $inv = $this->makeInvoice($organization, $store, $supplier, ['invoice_number' => "F-{$i}"]);
            $inst = $this->makeInstallment($inv);
            $this->forceApproved($inv);
            $this->makeObligation($organization, $store->id, null, $inst->id, '2026-12-21');
        }

        $service = app(FinanceMonthlySituationService::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $service->rows($organization, FinancePeriod::fromMonth('2026-12'), null, [])->getCollection();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(5, $rows);
        $invoice = $installment->supplierInvoice;
        $linked = $rows->firstWhere('id', $obligation->id);
        $this->assertSame(['id' => $invoice->id, 'internal_number' => $invoice->internal_number, 'invoice_number' => 'F-LINK'], $linked['supplier_invoice']);
        $this->assertTrue($linked['locked_by_invoice']);
        $legacy = $rows->first(fn ($r) => $r['supplier_invoice'] === null);
        $this->assertFalse($legacy['locked_by_invoice']);
        foreach (['id', 'number', 'category', 'label', 'store', 'supplier', 'reference', 'recurring_template_id'] as $key) {
            $this->assertArrayHasKey($key, $linked);
        }
        $this->assertLessThanOrEqual(14, $queries, 'Invoice link must be eager loaded (constant query count).');

        $future = $service->futureSupplierObligations($organization, FinancePeriod::fromMonth('2026-11'), null, null);
        $sources = collect($future)->pluck('source')->all();
        $this->assertContains('Facture fournisseur '.$invoice->internal_number, $sources);
        $this->assertContains('Obligation manuelle', $sources);
    }

    public function test_reset_orders_obligations_before_invoice_installments(): void
    {
        $operations = app(OrganizationDataResetBundleRegistry::class)->operationsFor(OrganizationDataResetScope::TransactionalFinance);
        $order = array_map(fn ($op) => $op->table, app(OrganizationDataResetDependencyGraph::class)->order($operations));
        $this->assertLessThan(array_search('supplier_invoice_installments', $order, true), array_search('finance_obligations', $order, true));
    }

    public function test_backup_and_restore_round_trip_keeps_linked_obligations_and_is_not_blocked_by_the_guard(): void
    {
        [$owner, $organization, , $installment, $obligation] = $this->linked();

        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        DB::table('finance_obligations')->where('id', $obligation->id)->update(['planned_amount' => '1.0000']);
        app(OrganizationBackupRestorer::class)->restore($archive->path, $organization, $owner);

        $row = DB::table('finance_obligations')->where('id', $obligation->id)->first();
        $this->assertSame('120.0000', (string) FinanceObligation::query()->findOrFail($obligation->id)->planned_amount);
        $this->assertSame($installment->id, (int) $row->supplier_invoice_installment_id);
        $this->assertSame(1, DB::table('supplier_invoice_installments')->where('id', $installment->id)->count());
    }

    public function test_migration_down_refuses_when_linked_obligations_exist(): void
    {
        $this->linked();
        $migration = require database_path('migrations/2026_11_26_000003_link_obligations_to_supplier_invoice_installments.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    private function assertRejected(callable $write, string $message): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException) {
            $this->assertTrue(true);

            return;
        }
        $this->fail("Expected database rejection: {$message}");
    }
}
