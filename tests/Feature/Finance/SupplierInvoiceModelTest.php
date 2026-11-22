<?php

namespace Tests\Feature\Finance;

use App\Enums\SupplierInvoiceStatus;
use App\Enums\SupplierInvoiceVoidReason;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceAttachment;
use App\Models\SupplierInvoiceInstallment;
use App\Models\SupplierInvoiceLine;
use App\Services\Finance\FinanceNumberGenerator;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\SupplierInvoiceTestCase;

class SupplierInvoiceModelTest extends SupplierInvoiceTestCase
{
    public function test_number_generator_formats_increments_and_is_per_organization_and_year(): void
    {
        [, $orgA] = $this->invoiceFixture('A');
        [, $orgB] = $this->invoiceFixture('B');
        $generator = app(FinanceNumberGenerator::class);

        $numbers = DB::transaction(fn () => [
            $generator->nextSupplierInvoice($orgA, 2026),
            $generator->nextSupplierInvoice($orgA, 2026),
            $generator->nextSupplierInvoice($orgA, 2027),
            $generator->nextSupplierInvoice($orgB, 2026),
        ]);

        $this->assertSame(['FAF-2026-000001', 'FAF-2026-000002', 'FAF-2027-000001', 'FAF-2026-000001'], $numbers);
        $this->assertSame(3, (int) DB::table('supplier_invoice_sequences')->where('organization_id', $orgA->id)->where('year', 2026)->value('next_number'));
    }

    public function test_number_generator_refuses_to_run_outside_a_transaction(): void
    {
        [, $organization] = $this->invoiceFixture();
        $connection = DB::connection();

        // The harness wraps each test in a transaction; temporarily unwind it to prove the guard.
        $level = $connection->transactionLevel();
        for ($i = 0; $i < $level; $i++) {
            $connection->rollBack($level - $i - 1);
        }
        try {
            $this->assertSame(0, $connection->transactionLevel());
            $this->expectException(LogicException::class);
            app(FinanceNumberGenerator::class)->nextSupplierInvoice($organization, 2026);
        } finally {
            for ($i = 0; $i < $level; $i++) {
                $connection->beginTransaction();
            }
        }
    }

    public function test_relations_and_casts(): void
    {
        [, $organization, $store, $supplier] = $this->invoiceFixture();
        $invoice = $this->makeInvoice($organization, $store, $supplier);
        $this->makeLine($invoice, 2);
        $this->makeLine($invoice, 1);
        $this->makeInstallment($invoice);

        $invoice = $invoice->fresh();
        $this->assertSame(SupplierInvoiceStatus::Draft, $invoice->status);
        $this->assertSame('120.0000', (string) $invoice->total_ttc);
        $this->assertSame([1, 2], $invoice->lines->pluck('position')->all());
        $this->assertCount(1, $invoice->installments);
        $this->assertSame($supplier->id, $invoice->supplier->id);
        $this->assertSame([$invoice->id], $supplier->supplierInvoices()->pluck('id')->all());
        $this->assertSame('2026-11-20', $invoice->invoice_date->toDateString());
    }

    public function test_draft_is_fully_editable_and_deletable(): void
    {
        [, $organization, $store, $supplier] = $this->invoiceFixture();
        $invoice = $this->makeInvoice($organization, $store, $supplier);
        $line = $this->makeLine($invoice);
        $installment = $this->makeInstallment($invoice);

        $invoice->notes = 'edited';
        $invoice->total_ttc = '99.0000';
        $invoice->save();
        $line->description = 'edited';
        $line->save();
        $installment->amount = '99.0000';
        $installment->save();
        $this->assertSame('edited', $invoice->fresh()->notes);

        $line->delete();
        $installment->delete();
        $invoice->fresh()->delete();
        $this->assertSame(0, DB::table('supplier_invoices')->count());
    }

    public function test_approved_invoice_lines_and_installments_are_immutable(): void
    {
        [, $organization, $store, $supplier] = $this->invoiceFixture();
        $invoice = $this->makeInvoice($organization, $store, $supplier);
        $line = $this->makeLine($invoice);
        $installment = $this->makeInstallment($invoice);
        $invoice = $this->forceApproved($invoice);

        $this->assertRefused(function () use ($invoice) {
            $invoice->notes = 'x';
            $invoice->save();
        });
        $this->assertRefused(function () use ($invoice) {
            $invoice->total_ttc = '1.0000';
            $invoice->save();
        });
        $this->assertRefused(function () use ($invoice) {
            $invoice->dedupe_key = null;
            $invoice->save();
        });
        $this->assertRefused(fn () => $invoice->delete());
        $this->assertRefused(function () use ($line) {
            $line->description = 'x';
            $line->save();
        });
        $this->assertRefused(fn () => $line->delete());
        $this->assertRefused(fn () => $this->makeLine($invoice, 2));
        $this->assertRefused(function () use ($installment) {
            $installment->amount = '1.0000';
            $installment->save();
        });
        $this->assertRefused(fn () => $installment->delete());
        $this->assertRefused(fn () => $this->makeInstallment($invoice, 2));
        $this->assertRefused(function () use ($invoice) {
            $invoice->status = 'draft';
            $invoice->save();
        });
        $this->assertRefused(function () use ($invoice) {
            $invoice->approved_at = null;
            $invoice->save();
        });

        $fresh = $invoice->fresh();
        $this->assertSame('120.0000', (string) $fresh->total_ttc);
        $this->assertNull($fresh->notes);
        $this->assertSame(1, $fresh->lines()->count());
    }

    public function test_draft_to_approved_transition_allows_only_the_whitelist(): void
    {
        [$owner, $organization, $store, $supplier] = $this->invoiceFixture();
        $draft = $this->makeInvoice($organization, $store, $supplier);

        $draft->status = SupplierInvoiceStatus::Approved;
        $draft->approved_at = now();
        $draft->approved_by_user_id = $owner->id;
        $draft->supplier_name_snapshot = $supplier->name;
        $draft->match_note = 'ok';
        $draft->save();
        $this->assertSame(SupplierInvoiceStatus::Approved, $draft->fresh()->status);

        // Approving while also changing the money is not a transition: refused.
        $other = $this->makeInvoice($organization, $store, $supplier);
        $this->assertRefused(function () use ($other) {
            $other->status = SupplierInvoiceStatus::Approved;
            $other->approved_at = now();
            $other->total_ttc = '5.0000';
            $other->save();
        });
        $this->assertSame('draft', $other->fresh()->status->value);
    }

    public function test_void_transition_allows_only_the_whitelist_and_void_is_terminal(): void
    {
        [$owner, $organization, $store, $supplier] = $this->invoiceFixture();
        $approved = $this->forceApproved($this->makeInvoice($organization, $store, $supplier, ['dedupe_key' => 'K1|2026']));

        $this->assertRefused(function () use ($approved, $owner) {
            $approved->status = SupplierInvoiceStatus::Void;
            $approved->voided_at = now();
            $approved->voided_by_user_id = $owner->id;
            $approved->void_reason_code = SupplierInvoiceVoidReason::DuplicateEntry;
            $approved->dedupe_key = null;
            $approved->notes = 'sneaky';
            $approved->save();
        });
        $approved = $approved->fresh();
        $this->assertSame('K1|2026', $approved->dedupe_key);

        $approved->status = SupplierInvoiceStatus::Void;
        $approved->voided_at = now();
        $approved->voided_by_user_id = $owner->id;
        $approved->void_reason_code = SupplierInvoiceVoidReason::DuplicateEntry;
        $approved->void_reason = 'Doublon';
        $approved->dedupe_key = null;
        $approved->save();

        $void = $approved->fresh();
        $this->assertSame(SupplierInvoiceStatus::Void, $void->status);
        $this->assertNull($void->dedupe_key);
        $this->assertRefused(function () use ($void) {
            $void->void_reason = 'changed';
            $void->save();
        });
        $this->assertRefused(function () use ($void) {
            $void->status = SupplierInvoiceStatus::Approved;
            $void->save();
        });
        $this->assertRefused(fn () => $void->delete());
    }

    public function test_draft_can_be_voided_directly(): void
    {
        [$owner, $organization, $store, $supplier] = $this->invoiceFixture();
        $draft = $this->makeInvoice($organization, $store, $supplier);
        $draft->status = SupplierInvoiceStatus::Void;
        $draft->voided_at = now();
        $draft->voided_by_user_id = $owner->id;
        $draft->void_reason_code = SupplierInvoiceVoidReason::WrongDataEntry;
        $draft->void_reason = 'Erreur';
        $draft->dedupe_key = null;
        $draft->save();
        $this->assertSame(SupplierInvoiceStatus::Void, $draft->fresh()->status);
    }

    public function test_attachments_may_be_added_to_an_approved_invoice_but_not_removed(): void
    {
        [, $organization, $store, $supplier] = $this->invoiceFixture();
        $invoice = $this->makeInvoice($organization, $store, $supplier);
        $make = function (string $sha) use ($invoice) {
            $attachment = new SupplierInvoiceAttachment;
            $attachment->organization_id = $invoice->organization_id;
            $attachment->supplier_invoice_id = $invoice->id;
            $attachment->disk = 'local';
            $attachment->storage_key = bin2hex(random_bytes(8));
            $attachment->original_name = 'scan.pdf';
            $attachment->mime = 'application/pdf';
            $attachment->size_bytes = 10;
            $attachment->sha256 = str_repeat($sha, 64);
            $attachment->save();

            return $attachment;
        };

        $make('a')->delete();
        $this->assertSame(0, DB::table('supplier_invoice_attachments')->count());

        $this->forceApproved($invoice);
        $kept = $make('c');
        $this->assertRefused(fn () => $kept->delete());
        $this->assertSame(1, SupplierInvoiceAttachment::query()->count());
    }

    public function test_models_are_not_mass_assignable(): void
    {
        foreach ([SupplierInvoice::class, SupplierInvoiceLine::class, SupplierInvoiceInstallment::class, SupplierInvoiceAttachment::class] as $class) {
            $this->assertSame(['*'], (new $class)->getGuarded(), $class);
        }
    }

    private function assertRefused(callable $mutation): void
    {
        try {
            $mutation();
            $this->fail('Expected the immutability guard to refuse the write.');
        } catch (LogicException $e) {
            $this->assertMatchesRegularExpression('/immutable|cannot be removed/', $e->getMessage());
        }
    }
}
