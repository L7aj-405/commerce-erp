<?php

namespace Tests\Feature\Finance;

use App\Services\Finance\FiscalDocumentProtection;
use App\Services\OrganizationBackups\OrganizationBackupExporter;
use App\Services\OrganizationBackups\OrganizationBackupRestorer;
use App\Services\OrganizationBackups\OrganizationBackupSchema;
use App\Services\OrganizationDataReset\OrganizationDataResetBundleRegistry;
use App\Services\OrganizationDataReset\OrganizationDataResetDependencyGraph;
use App\Services\OrganizationDataReset\OrganizationDataResetExecutor;
use App\Services\OrganizationDataReset\OrganizationDataResetPreviewService;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use App\Services\OrganizationDataReset\OrganizationDataResetSequenceGuard;
use App\Services\Sandbox\SandboxInventoryGuard;
use Illuminate\Support\Facades\DB;
use Tests\Support\SupplierInvoiceTestCase;

/** The five supplier invoice tables are wired into every cross-cutting registry. */
class SupplierInvoiceRegistryTest extends SupplierInvoiceTestCase
{
    private const TABLES = ['supplier_invoice_sequences', 'supplier_invoices', 'supplier_invoice_lines', 'supplier_invoice_installments', 'supplier_invoice_attachments'];

    public function test_sandbox_guard_lists_every_supplier_invoice_table(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertContains($table, SandboxInventoryGuard::PRODUCTION_TABLES, $table);
        }
    }

    public function test_fiscal_protection_covers_approved_supplier_invoices_only(): void
    {
        [, $organization, $store, $supplier] = $this->invoiceFixture();
        $draft = $this->makeInvoice($organization, $store, $supplier);
        $protection = app(FiscalDocumentProtection::class);

        $this->assertContains('supplier_invoices', $protection->tables());
        $this->assertSame(0, $protection->protectedCounts($organization->fresh())['supplier_invoices']);

        $approved = $this->forceApproved($this->makeInvoice($organization, $store, $supplier));
        $this->assertSame(1, $protection->protectedCounts($organization->fresh())['supplier_invoices']);

        $blockers = $protection->selectiveBlockers($organization->fresh(), ['supplier_invoices' => [$draft->id, $approved->id]]);
        $this->assertSame('supplier_invoices', $blockers[0]['table']);
        $this->assertSame([$approved->internal_number], $blockers[0]['samples']);

        // A voided invoice keeps approved_at and stays protected history.
        DB::table('supplier_invoices')->where('id', $approved->id)->update(['status' => 'void', 'dedupe_key' => null]);
        $this->assertSame(1, $protection->protectedCounts($organization->fresh())['supplier_invoices']);
    }

    public function test_backup_schema_lists_the_tables_parents_first(): void
    {
        $tables = app(OrganizationBackupSchema::class)->tenantTables();
        foreach (self::TABLES as $table) {
            $this->assertContains($table, $tables, $table);
        }
        $this->assertLessThan(array_search('supplier_invoices', $tables, true), array_search('suppliers', $tables, true));
        $this->assertLessThan(array_search('supplier_invoice_lines', $tables, true), array_search('supplier_invoices', $tables, true));
    }

    public function test_backup_and_restore_round_trip_supplier_invoices(): void
    {
        [$owner, $organization, $store, $supplier] = $this->invoiceFixture();
        $invoice = $this->makeInvoice($organization, $store, $supplier, ['invoice_number' => 'F-ROUNDTRIP']);
        $this->makeLine($invoice);
        $this->makeInstallment($invoice);
        DB::table('supplier_invoice_attachments')->insert(['organization_id' => $organization->id, 'supplier_invoice_id' => $invoice->id, 'disk' => 'local', 'storage_key' => 'k1', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size_bytes' => 5, 'sha256' => str_repeat('d', 64), 'created_at' => now(), 'updated_at' => now()]);
        $approved = $this->forceApproved($this->makeInvoice($organization, $store, $supplier, ['invoice_number' => 'F-APPROVED']));

        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        foreach (self::TABLES as $table) {
            $this->assertContains($table, $archive->manifest['tables'], $table);
        }

        // Mutate after the backup, then restore: the backed-up state returns.
        DB::table('supplier_invoices')->where('id', $invoice->id)->update(['invoice_number' => 'MUTATED']);
        DB::table('supplier_invoice_lines')->where('supplier_invoice_id', $invoice->id)->delete();
        DB::table('supplier_invoice_attachments')->delete();
        app(OrganizationBackupRestorer::class)->restore($archive->path, $organization, $owner);

        $this->assertSame('F-ROUNDTRIP', DB::table('supplier_invoices')->where('id', $invoice->id)->value('invoice_number'));
        $this->assertSame('approved', DB::table('supplier_invoices')->where('id', $approved->id)->value('status'));
        $this->assertSame(1, DB::table('supplier_invoice_lines')->where('supplier_invoice_id', $invoice->id)->count());
        $this->assertSame(1, DB::table('supplier_invoice_installments')->where('supplier_invoice_id', $invoice->id)->count());
        $this->assertSame(1, DB::table('supplier_invoice_attachments')->count());
        $this->assertSame(3, (int) DB::table('supplier_invoice_sequences')->where('organization_id', $organization->id)->value('next_number'));
    }

    public function test_reset_registry_and_graph_order_children_before_parents(): void
    {
        $operations = app(OrganizationDataResetBundleRegistry::class)->operationsFor(OrganizationDataResetScope::TransactionalFinance);
        $deleted = array_values(array_unique(array_map(fn ($op) => $op->table, $operations)));
        foreach (['supplier_invoice_attachments', 'supplier_invoice_installments', 'supplier_invoice_lines', 'supplier_invoices'] as $table) {
            $this->assertContains($table, $deleted, $table);
        }
        $this->assertNotContains('supplier_invoice_sequences', $deleted, 'Numbering sequences are never reset.');

        $order = array_map(fn ($op) => $op->table, app(OrganizationDataResetDependencyGraph::class)->order($operations));
        $position = fn (string $table) => array_search($table, $order, true);
        $this->assertLessThan($position('supplier_invoices'), $position('supplier_invoice_lines'));
        $this->assertLessThan($position('supplier_invoices'), $position('supplier_invoice_installments'));
        $this->assertLessThan($position('supplier_invoices'), $position('supplier_invoice_attachments'));

        $this->assertContains('supplier_invoice_sequences', app(OrganizationDataResetSequenceGuard::class)->protectedTables());
    }

    public function test_finance_reset_is_blocked_by_an_approved_invoice_but_removes_drafts(): void
    {
        [$owner, $organization, $store, $supplier] = $this->invoiceFixture();
        $draft = $this->makeInvoice($organization, $store, $supplier);
        $this->makeLine($draft);
        $this->makeInstallment($draft);
        $this->activate($owner, $organization);

        $plan = app(OrganizationDataResetPreviewService::class)->preview($organization->fresh(), OrganizationDataResetScope::TransactionalFinance);
        $this->assertNotContains('fiscal.protected_documents.supplier_invoices', array_column($plan->blockers, 'code'));
        $this->assertTrue($plan->isExecutable(), json_encode($plan->blockers));
        app(OrganizationDataResetExecutor::class)->execute($organization->fresh(), $owner, $plan);
        $this->assertSame(0, DB::table('supplier_invoices')->count());
        $this->assertSame(0, DB::table('supplier_invoice_lines')->count());
        $this->assertSame(0, DB::table('supplier_invoice_installments')->count());

        $approved = $this->forceApproved($this->makeInvoice($organization, $store, $supplier));
        $plan = app(OrganizationDataResetPreviewService::class)->preview($organization->fresh(), OrganizationDataResetScope::TransactionalFinance);
        $this->assertContains('fiscal.protected_documents.supplier_invoices', array_column($plan->blockers, 'code'));
        $this->assertFalse($plan->isExecutable());
        $this->assertSame(1, DB::table('supplier_invoices')->where('id', $approved->id)->count());
    }

    public function test_suppliers_reset_reports_finance_dependency_on_supplier_invoices(): void
    {
        [$owner, $organization, $store, $supplier] = $this->invoiceFixture();
        $this->makeInvoice($organization, $store, $supplier);
        $this->activate($owner, $organization);

        $plan = app(OrganizationDataResetPreviewService::class)->preview($organization->fresh(), OrganizationDataResetScope::SuppliersAndPurchasing);
        $this->assertContains('suppliers.finance_dependencies', array_column($plan->blockers, 'code'));
        $this->assertFalse($plan->isExecutable());
    }
}
