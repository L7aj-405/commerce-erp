<?php

namespace Tests\Feature\Finance;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\SupplierInvoiceTestCase;

/**
 * Phase 2 Sprint 1 (PR2): database-level guarantees of the supplier invoice
 * tables. Every rejection test bypasses the models on purpose (raw query builder).
 */
class SupplierInvoiceSchemaTest extends SupplierInvoiceTestCase
{
    public function test_tables_and_key_columns_exist(): void
    {
        foreach (['supplier_invoice_sequences', 'supplier_invoices', 'supplier_invoice_lines', 'supplier_invoice_installments', 'supplier_invoice_attachments'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumn($table, 'organization_id'), $table);
        }
        $this->assertTrue(Schema::hasColumns('supplier_invoices', ['dedupe_key', 'client_operation_id', 'row_version', 'control_total_ttc', 'void_reason_code']));
        // The obligation link is single-direction and lives on finance_obligations (later migration).
        $this->assertFalse(Schema::hasColumn('supplier_invoice_installments', 'obligation_id'));
        $this->assertFalse(Schema::hasColumn('finance_obligations', 'supplier_invoice_id'));
    }

    public function test_invoice_check_constraints_reject_negative_amounts_and_allow_zero(): void
    {
        [, $organization, $store, $supplier] = $this->invoiceFixture();
        $invoice = $this->makeInvoice($organization, $store, $supplier);

        foreach (['subtotal_ht', 'total_tva', 'total_ttc'] as $column) {
            $this->assertRejected(fn () => DB::table('supplier_invoices')->where('id', $invoice->id)->update([$column => '-0.0100']), $column);
            DB::table('supplier_invoices')->where('id', $invoice->id)->update([$column => '0.0000']);
        }
        // A rounding adjustment may be negative.
        DB::table('supplier_invoices')->where('id', $invoice->id)->update(['rounding_adjustment' => '-0.0300']);
        $this->assertSame('-0.0300', (string) $invoice->fresh()->rounding_adjustment);

        $row = (array) DB::table('supplier_invoices')->where('id', $invoice->id)->first();
        unset($row['id']);
        $row['internal_number'] = 'FAF-2026-999999';
        $row['dedupe_key'] = null;
        $row['total_ttc'] = '-1.0000';
        $this->assertRejected(fn () => DB::table('supplier_invoices')->insert($row), 'insert');
    }

    public function test_line_and_installment_check_constraints(): void
    {
        [, $organization, $store, $supplier] = $this->invoiceFixture();
        $invoice = $this->makeInvoice($organization, $store, $supplier);
        $line = $this->makeLine($invoice);
        $installment = $this->makeInstallment($invoice);

        foreach (['0.0000', '-1.0000'] as $bad) {
            $this->assertRejected(fn () => DB::table('supplier_invoice_lines')->where('id', $line->id)->update(['quantity' => $bad]), 'quantity '.$bad);
            $this->assertRejected(fn () => DB::table('supplier_invoice_installments')->where('id', $installment->id)->update(['amount' => $bad]), 'amount '.$bad);
        }
        $this->assertRejected(fn () => DB::table('supplier_invoice_lines')->where('id', $line->id)->update(['unit_price_ht' => '-0.0001']), 'unit price');
        DB::table('supplier_invoice_lines')->where('id', $line->id)->update(['unit_price_ht' => '0.0000', 'quantity' => '0.0001']);
        DB::table('supplier_invoice_installments')->where('id', $installment->id)->update(['amount' => '0.0001']);
        $this->assertSame('0.0001', (string) $installment->fresh()->amount);
    }

    public function test_dedupe_key_is_unique_per_supplier_and_null_never_collides(): void
    {
        [, $organization, $store, $supplier] = $this->invoiceFixture();
        $other = $this->makeSupplier($organization, 'Autre');

        $this->makeInvoice($organization, $store, $supplier, ['dedupe_key' => 'FA001|2026']);
        $this->makeInvoice($organization, $store, $other, ['dedupe_key' => 'FA001|2026']); // same key, other supplier
        $this->makeInvoice($organization, $store, $supplier, ['dedupe_key' => 'FA001|2025']); // other year

        $this->assertRejected(fn () => $this->makeInvoice($organization, $store, $supplier, ['dedupe_key' => 'FA001|2026']), 'duplicate key', '/unique|duplicate/i');

        // Void invoices carry a NULL key: any number of them may coexist, and the number is freed.
        $this->makeInvoice($organization, $store, $supplier, ['dedupe_key' => null, 'status' => 'void']);
        $this->makeInvoice($organization, $store, $supplier, ['dedupe_key' => null, 'status' => 'void']);
        $this->assertSame(2, DB::table('supplier_invoices')->where('supplier_id', $supplier->id)->whereNull('dedupe_key')->count());
    }

    public function test_internal_number_and_client_operation_id_are_unique_per_organization(): void
    {
        [, $organization, $store, $supplier] = $this->invoiceFixture();
        $invoice = $this->makeInvoice($organization, $store, $supplier);

        $this->assertRejected(fn () => $this->makeInvoice($organization, $store, $supplier, ['internal_number' => $invoice->internal_number]), 'number', '/unique|duplicate/i');

        $uuid = (string) Str::uuid();
        DB::table('supplier_invoices')->where('id', $invoice->id)->update(['client_operation_id' => $uuid]);
        $second = $this->makeInvoice($organization, $store, $supplier);
        $this->assertRejected(fn () => DB::table('supplier_invoices')->where('id', $second->id)->update(['client_operation_id' => $uuid]), 'client op', '/unique|duplicate/i');
    }

    public function test_composite_foreign_keys_refuse_cross_tenant_references(): void
    {
        [, $orgA, $storeA, $supplierA] = $this->invoiceFixture('A');
        [, $orgB, $storeB, $supplierB] = $this->invoiceFixture('B');
        $invoiceB = $this->makeInvoice($orgB, $storeB, $supplierB);
        $accountA = $this->createFinancialAccount($orgA);
        $taxA = $this->createTaxRate($orgA);
        $poA = DB::table('purchase_orders')->insertGetId([
            'organization_id' => $orgA->id, 'store_id' => $storeA->id, 'supplier_id' => $supplierA->id,
            'purchase_order_number' => 'BCF-000001', 'status' => 'ordered', 'currency_code' => 'MAD',
            'order_date' => '2026-11-01', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The invoice row itself: store / supplier / purchase order of another organization.
        $this->assertRejected(fn () => $this->makeInvoice($orgB, $storeA, $supplierB), 'store', '/foreign key/i');
        $this->assertRejected(fn () => $this->makeInvoice($orgB, $storeB, $supplierA), 'supplier', '/foreign key/i');
        $this->assertRejected(fn () => DB::table('supplier_invoices')->where('id', $invoiceB->id)->update(['purchase_order_id' => $poA]), 'purchase order', '/foreign key/i');

        // Children: invoice / account / tax rate of another organization.
        $line = $this->lineRow($invoiceB);
        $this->assertRejected(fn () => DB::table('supplier_invoice_lines')->insert(array_replace($line, ['organization_id' => $orgA->id])), 'line invoice', '/foreign key/i');
        $this->assertRejected(fn () => DB::table('supplier_invoice_lines')->insert(array_replace($line, ['tax_rate_id' => $taxA->id])), 'line tax', '/foreign key/i');
        $installment = ['organization_id' => $orgB->id, 'supplier_invoice_id' => $invoiceB->id, 'position' => 1, 'due_date' => '2026-12-01', 'amount' => '10.0000', 'created_at' => now(), 'updated_at' => now()];
        $this->assertRejected(fn () => DB::table('supplier_invoice_installments')->insert(array_replace($installment, ['preferred_financial_account_id' => $accountA->id])), 'installment account', '/foreign key/i');
        $this->assertRejected(fn () => DB::table('supplier_invoice_installments')->insert(array_replace($installment, ['organization_id' => $orgA->id])), 'installment invoice', '/foreign key/i');
        $attachment = ['organization_id' => $orgA->id, 'supplier_invoice_id' => $invoiceB->id, 'disk' => 'local', 'storage_key' => 'k', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()];
        $this->assertRejected(fn () => DB::table('supplier_invoice_attachments')->insert($attachment), 'attachment invoice', '/foreign key/i');

        // Same-tenant references still work.
        DB::table('supplier_invoice_lines')->insert($line);
        $this->assertSame(1, DB::table('supplier_invoice_lines')->count());
    }

    public function test_attachment_hash_is_unique_per_invoice_only(): void
    {
        [, $organization, $store, $supplier] = $this->invoiceFixture();
        $one = $this->makeInvoice($organization, $store, $supplier);
        $two = $this->makeInvoice($organization, $store, $supplier);
        $row = fn ($invoice) => ['organization_id' => $organization->id, 'supplier_invoice_id' => $invoice->id, 'disk' => 'local', 'storage_key' => bin2hex(random_bytes(8)), 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now()];

        DB::table('supplier_invoice_attachments')->insert($row($one));
        DB::table('supplier_invoice_attachments')->insert($row($two));
        $this->assertRejected(fn () => DB::table('supplier_invoice_attachments')->insert($row($one)), 'same hash', '/unique|duplicate/i');
    }

    public function test_referenced_parents_cannot_be_deleted(): void
    {
        [, $organization, $store, $supplier] = $this->invoiceFixture();
        $this->makeInvoice($organization, $store, $supplier);

        $this->assertRejected(fn () => DB::table('suppliers')->where('id', $supplier->id)->delete(), 'supplier delete', '/foreign key/i');
        $this->assertRejected(fn () => DB::table('stores')->where('id', $store->id)->delete(), 'store delete', '/foreign key/i');
    }

    public function test_down_refuses_loudly_when_invoices_exist(): void
    {
        [, $organization, $store, $supplier] = $this->invoiceFixture();
        $this->makeInvoice($organization, $store, $supplier);
        $migration = require base_path('database/migrations/2026_11_26_000002_create_supplier_invoice_tables.php');

        try {
            $migration->down();
            $this->fail('down() must refuse while supplier invoices exist.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to drop supplier invoice tables', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('supplier_invoices'));
        $this->assertSame(1, DB::table('supplier_invoices')->count());
    }

    /** @return array<string,mixed> */
    private function lineRow($invoice): array
    {
        return [
            'organization_id' => $invoice->organization_id, 'supplier_invoice_id' => $invoice->id, 'position' => 1,
            'cost_nature' => 'expense', 'description' => 'Ligne', 'quantity' => '1.0000', 'unit_price_ht' => '10.0000',
            'tax_rate_id' => null, 'tax_rate_percent' => '20.0000', 'line_ht' => '10.0000', 'line_tva' => '2.0000', 'line_ttc' => '12.0000',
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function assertRejected(callable $mutation, string $why = '', string $pattern = '/chk_|check constraint/i'): void
    {
        try {
            $mutation();
            $this->fail("Expected the database to reject the write ({$why}).");
        } catch (QueryException $e) {
            $this->assertMatchesRegularExpression($pattern, $e->getMessage(), $why);
        }
    }
}
