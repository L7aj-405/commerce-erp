<?php

namespace Tests\Feature\Finance;

use App\Models\Organization;
use App\Models\SupplierInvoice;
use App\Services\Finance\FinanceReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SupplierInvoiceTestCase;

/**
 * Phase 2 Sprint 1 (PR10): read-only reconciliation checks R-8 (invoice total = Σ obligations),
 * R-9 (installments <-> obligations 1:1) and R-10 (posted allocations <= planned; none on cancelled).
 * Corruptions are written with the raw query builder on purpose (they bypass every model guard).
 */
class FinanceReconcileSupplierInvoiceTest extends SupplierInvoiceTestCase
{
    private int $seq = 0;

    /** @return array{Organization, SupplierInvoice, list<int>, list<int>} org, approved invoice (120 = 60 + 60), installment ids, obligation ids */
    private function approvedInvoice(): array
    {
        [, $organization, $store, $supplier] = $this->invoiceFixture();
        $invoice = $this->makeInvoice($organization, $store, $supplier);
        $this->makeLine($invoice);
        $installments = [];
        foreach ([1 => ['2026-12-20', '60.0000'], 2 => ['2027-01-20', '60.0000']] as $position => [$due, $amount]) {
            $installments[] = DB::table('supplier_invoice_installments')->insertGetId([
                'organization_id' => $organization->id, 'supplier_invoice_id' => $invoice->id, 'position' => $position,
                'due_date' => $due, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $invoice = $this->forceApproved($invoice);
        $obligations = array_map(fn (int $installmentId) => $this->obligation($invoice, $installmentId), $installments);

        return [$organization, $invoice, $installments, $obligations];
    }

    /** @param array<string,mixed> $overrides */
    private function obligation(SupplierInvoice $invoice, ?int $installmentId, array $overrides = []): int
    {
        $installment = $installmentId ? DB::table('supplier_invoice_installments')->find($installmentId) : null;

        return DB::table('finance_obligations')->insertGetId(array_replace([
            'organization_id' => $invoice->organization_id, 'store_id' => $invoice->store_id, 'obligation_number' => 'OBL-T-'.(++$this->seq),
            'category' => 'supplier', 'label' => 'Facture', 'supplier_id' => $invoice->supplier_id, 'due_date' => $installment?->due_date ?? '2026-12-20',
            'planned_amount' => $installment?->amount ?? '10.0000', 'currency_code' => 'MAD', 'supplier_invoice_installment_id' => $installmentId,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function allocate(Organization $organization, int $obligationId, string $amount, string $status = 'posted'): void
    {
        $account = $this->createFinancialAccount($organization, ['code' => 'A'.(++$this->seq), 'name' => 'Compte '.$this->seq]);
        $paymentId = DB::table('finance_outgoing_payments')->insertGetId([
            'organization_id' => $organization->id, 'payment_number' => 'DSP-T-'.(++$this->seq), 'financial_account_id' => $account->id,
            'payment_method' => 'bank_transfer', 'amount' => $amount, 'currency_code' => 'MAD', 'payment_date' => '2026-12-21',
            'status' => $status, 'idempotency_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('finance_outgoing_payment_allocations')->insert([
            'organization_id' => $organization->id, 'outgoing_payment_id' => $paymentId, 'obligation_id' => $obligationId,
            'amount' => $amount, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function check(Organization $organization, string $code): array
    {
        $results = collect(app(FinanceReconciliationService::class)->run($organization, ['2026-12']))->keyBy('code');
        $this->assertTrue($results->has($code), $code);

        return $results[$code];
    }

    private function assertAllPass(Organization $organization): void
    {
        foreach (['R-8', 'R-9', 'R-10'] as $code) {
            $this->assertSame('pass', $this->check($organization, $code)['status'], $code.' '.json_encode($this->check($organization, $code)['samples']));
        }
    }

    public function test_run_includes_the_three_checks_and_passes_without_any_supplier_invoice(): void
    {
        [, $organization] = $this->invoiceFixture();
        $codes = array_column(app(FinanceReconciliationService::class)->run($organization, ['2026-12']), 'code');
        $this->assertSame(['R-1', 'R-2', 'R-3', 'R-4', 'R-5', 'R-6', 'R-7', 'R-8', 'R-9', 'R-10'], $codes);
        $this->assertAllPass($organization);
    }

    public function test_clean_data_passes_including_legacy_obligations_drafts_and_void_invoices(): void
    {
        [$organization, $invoice, , $obligations] = $this->approvedInvoice();
        $this->allocate($organization, $obligations[0], '60.0000');
        $this->allocate($organization, $obligations[1], '20.0000');
        $this->allocate($organization, $obligations[1], '40.0000', 'reversed');
        // A legacy manual obligation (no link) is ignored, even over-allocated.
        $legacy = $this->obligation($invoice, null, ['planned_amount' => '5.0000']);
        $this->allocate($organization, $legacy, '500.0000');
        // Draft invoice: nothing generated. Void invoice: its obligation is cancelled.
        $store = $invoice->store;
        $supplier = $invoice->supplier;
        $draft = $this->makeInvoice($organization, $store, $supplier);
        $this->makeInstallment($draft);
        $void = $this->makeInvoice($organization, $store, $supplier);
        $voidInstallment = $this->makeInstallment($void);
        $this->obligation($void, $voidInstallment->id, ['cancelled_at' => now()]);
        DB::table('supplier_invoices')->where('id', $void->id)->update(['status' => 'void', 'voided_at' => now(), 'approved_at' => now(), 'dedupe_key' => null]);

        $this->assertAllPass($organization);
        $this->assertStringContainsString('Approved supplier invoices checked: 1', (string) $this->check($organization, 'R-8')['note']);
    }

    public function test_r8_fails_on_amount_mismatch_missing_obligation_and_extra_obligation(): void
    {
        [$organization, $invoice, $installments, $obligations] = $this->approvedInvoice();

        DB::table('finance_obligations')->where('id', $obligations[0])->update(['planned_amount' => '59.0000']);
        $r8 = $this->check($organization, 'R-8');
        $this->assertSame('fail', $r8['status']);
        $this->assertSame(1, $r8['violations']);
        $this->assertSame($invoice->id, $r8['samples'][0]['supplier_invoice_id']);
        $this->assertEquals('120.0000', $r8['samples'][0]['invoice_total']);
        $this->assertEquals('119.0000', $r8['samples'][0]['obligations_total']);

        DB::table('finance_obligations')->where('id', $obligations[0])->update(['planned_amount' => '60.0000']);
        $this->assertSame('pass', $this->check($organization, 'R-8')['status']);

        // A cancelled obligation no longer counts: the invoice is short.
        DB::table('finance_obligations')->where('id', $obligations[1])->update(['cancelled_at' => now()]);
        $this->assertSame('fail', $this->check($organization, 'R-8')['status']);

        // Obligation removed altogether.
        DB::table('finance_obligations')->where('id', $obligations[1])->delete();
        $this->assertSame('fail', $this->check($organization, 'R-8')['status']);
    }

    public function test_r9_fails_on_missing_obligation_wrong_due_date_amount_store_and_live_obligation_on_a_draft(): void
    {
        [$organization, $invoice, $installments, $obligations] = $this->approvedInvoice();
        $this->assertSame('pass', $this->check($organization, 'R-9')['status']);

        DB::table('finance_obligations')->where('id', $obligations[0])->update(['due_date' => '2026-12-21']);
        $r9 = $this->check($organization, 'R-9');
        $this->assertSame('fail', $r9['status']);
        $this->assertSame('obligation_differs_from_installment', $r9['samples'][0]['issue']);
        $this->assertSame($installments[0], $r9['samples'][0]['installment_id']);
        DB::table('finance_obligations')->where('id', $obligations[0])->update(['due_date' => '2026-12-20', 'planned_amount' => '61.0000']);
        $this->assertSame('fail', $this->check($organization, 'R-9')['status']);
        DB::table('finance_obligations')->where('id', $obligations[0])->update(['planned_amount' => '60.0000', 'currency_code' => 'EUR']);
        $this->assertSame('fail', $this->check($organization, 'R-9')['status']);
        DB::table('finance_obligations')->where('id', $obligations[0])->update(['currency_code' => 'MAD', 'supplier_id' => null]);
        $this->assertSame('fail', $this->check($organization, 'R-9')['status']);
        DB::table('finance_obligations')->where('id', $obligations[0])->update(['supplier_id' => $invoice->supplier_id]);
        $this->assertSame('pass', $this->check($organization, 'R-9')['status']);

        DB::table('finance_obligations')->where('id', $obligations[1])->delete();
        $missing = $this->check($organization, 'R-9');
        $this->assertSame('fail', $missing['status']);
        $this->assertSame('installment_without_obligation', $missing['samples'][0]['issue']);
        $this->assertSame($installments[1], $missing['samples'][0]['installment_id']);

        // A live obligation on a draft invoice (and on a void one) is a violation; cancelled ones are fine.
        [$other, $draft] = [$organization, $this->makeInvoice($organization, $invoice->store, $invoice->supplier)];
        $draftInstallment = $this->makeInstallment($draft);
        $draftObligation = $this->obligation($draft, $draftInstallment->id);
        DB::table('finance_obligations')->where('id', $obligations[1] ?? 0)->delete();
        $codes = array_column($this->check($other, 'R-9')['samples'], 'issue');
        $this->assertContains('live_obligation_on_non_approved_invoice', $codes);
        DB::table('finance_obligations')->where('id', $draftObligation)->update(['cancelled_at' => now()]);
        $this->assertNotContains('live_obligation_on_non_approved_invoice', array_column($this->check($other, 'R-9')['samples'], 'issue'));
    }

    public function test_r10_fails_on_over_allocation_and_on_posted_allocation_on_a_cancelled_obligation(): void
    {
        [$organization, , , $obligations] = $this->approvedInvoice();
        $this->allocate($organization, $obligations[0], '60.0000');
        $this->allocate($organization, $obligations[1], '60.0000', 'reversed'); // reversed: excluded
        $this->assertSame('pass', $this->check($organization, 'R-10')['status']);

        $this->allocate($organization, $obligations[0], '0.0100');
        $r10 = $this->check($organization, 'R-10');
        $this->assertSame('fail', $r10['status']);
        $this->assertSame(1, $r10['violations']);
        $this->assertSame('over_allocated', $r10['samples'][0]['issue']);
        $this->assertEquals('60.0100', $r10['samples'][0]['allocated']);

        DB::table('finance_obligations')->where('id', $obligations[0])->update(['cancelled_at' => now()]);
        $r10 = $this->check($organization, 'R-10');
        $this->assertSame('fail', $r10['status']);
        $this->assertSame('posted_allocation_on_cancelled_obligation', $r10['samples'][0]['issue']);

        // Reversed allocation on a cancelled obligation is fine.
        DB::table('finance_obligations')->where('id', $obligations[1])->update(['cancelled_at' => now()]);
        $this->assertSame(1, $this->check($organization, 'R-10')['violations']);
    }

    public function test_checks_are_organization_scoped_and_read_only(): void
    {
        [$organization, , , $obligations] = $this->approvedInvoice();
        [, $other] = $this->invoiceFixture('Other');
        DB::table('finance_obligations')->where('id', $obligations[0])->update(['planned_amount' => '1.0000']);

        foreach (['R-8', 'R-9', 'R-10'] as $code) {
            $this->assertSame('pass', $this->check($other, $code)['status'], $code);
        }
        $before = [DB::table('finance_obligations')->get()->toJson(), DB::table('supplier_invoices')->get()->toJson(), DB::table('audit_logs')->count()];
        $this->assertSame('fail', $this->check($organization, 'R-8')['status']);
        $this->assertSame($before, [DB::table('finance_obligations')->get()->toJson(), DB::table('supplier_invoices')->get()->toJson(), DB::table('audit_logs')->count()]);
    }

    public function test_command_reports_the_new_checks_and_fails_on_variance(): void
    {
        [$organization, , , $obligations] = $this->approvedInvoice();
        $this->artisan('finance:reconcile', ['organization' => $organization->id, '--json' => true])->assertExitCode(0);

        DB::table('finance_obligations')->where('id', $obligations[0])->update(['planned_amount' => '1.0000']);
        $this->artisan('finance:reconcile', ['organization' => $organization->id])
            ->expectsOutputToContain('R-8')->expectsOutputToContain('R-10')->assertExitCode(1);
    }
}
