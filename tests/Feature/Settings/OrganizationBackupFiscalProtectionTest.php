<?php

namespace Tests\Feature\Settings;

use App\Actions\OrganizationBackups\RestoreOrganizationBackupAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\OrganizationBackups\OrganizationBackupException;
use App\Services\OrganizationBackups\OrganizationBackupExporter;
use Illuminate\Support\Facades\DB;
use Tests\Support\DocumentTestCase;

/**
 * Phase 1 Step 10 (S-08): restoring a backup must never delete or roll back protected fiscal documents
 * (issued invoices / credit notes, payments, refunds, outgoing payments) or re-open a closed month.
 */
class OrganizationBackupFiscalProtectionTest extends DocumentTestCase
{
    public function test_restore_is_refused_when_a_payment_was_posted_after_the_backup(): void
    {
        [$owner, $organization, $order, $account] = $this->fixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        $later = $this->laterPayment($owner, $organization, $account);

        $this->assertRefused(fn () => $this->restore($owner, $organization, $archive->path), 'payments: 1 supprimé(s)');

        // Nothing was replaced or deleted: the post-backup payment is still there.
        $this->assertDatabaseHas('payments', ['id' => $later->id, 'payment_number' => $later->payment_number]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'organization_backup.restore_failed', 'organization_id' => $organization->id]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'organization_backup.restored', 'organization_id' => $organization->id]);
    }

    public function test_restore_is_refused_when_a_protected_document_changed_state_after_the_backup(): void
    {
        [$owner, $organization, $order, $account, $payment, $invoice] = $this->fixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        DB::table('payments')->where('id', $payment->id)->update(['status' => 'reversed']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'superseded']);

        $this->assertRefused(fn () => $this->restore($owner, $organization, $archive->path), 'modifié(s)');

        $this->assertSame('reversed', DB::table('payments')->where('id', $payment->id)->value('status'));
        $this->assertSame('superseded', DB::table('invoices')->where('id', $invoice->id)->value('status'));
    }

    public function test_restore_is_refused_when_it_would_reopen_a_closed_month(): void
    {
        [$owner, $organization] = $this->fixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        DB::table('finance_month_reviews')->insert([
            'organization_id' => $organization->id, 'period' => '2026-03-01', 'status' => 'closed',
            'closed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertRefused(fn () => $this->restore($owner, $organization, $archive->path), 'mois clôturés');

        $this->assertDatabaseHas('finance_month_reviews', ['organization_id' => $organization->id, 'status' => 'closed']);
    }

    public function test_restore_is_validated_when_protected_documents_are_unchanged(): void
    {
        [$owner, $organization, , , $payment, $invoice] = $this->fixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        DB::table('products')->where('organization_id', $organization->id)->update(['name' => 'Renamed after backup']);

        $this->restore($owner, $organization, $archive->path);

        $this->assertDatabaseHas('products', ['organization_id' => $organization->id, 'name' => 'Backup Product']);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'invoice_number' => $invoice->invoice_number]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'organization_backup.restored', 'organization_id' => $organization->id]);
    }

    public function test_test_mode_organizations_are_not_protected(): void
    {
        [$owner, $organization, , $account] = $this->fixture();
        DB::table('organizations')->where('id', $organization->id)->update(['fiscal_data_mode' => 'test']);
        $archive = app(OrganizationBackupExporter::class)->create($organization->fresh(), $owner);
        $later = $this->laterPayment($owner, $organization, $account);

        $this->restore($owner, $organization->fresh(), $archive->path);

        $this->assertDatabaseMissing('payments', ['id' => $later->id]);
    }

    public function test_another_organizations_documents_never_block_a_restore(): void
    {
        [$owner, $organization] = $this->fixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        [$otherOwner, , , $otherAccount] = $this->fixture();
        $this->laterPayment($otherOwner, Organization::query()->findOrFail($otherAccount->organization_id), $otherAccount);

        $this->restore($owner, $organization, $archive->path);

        $this->assertDatabaseHas('audit_logs', ['event' => 'organization_backup.restored', 'organization_id' => $organization->id]);
    }

    public function test_timed_restore_exercise_completes_and_reports_its_duration(): void
    {
        [$owner, $organization] = $this->fixture();
        $started = microtime(true);
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        $backupSeconds = microtime(true) - $started;

        $started = microtime(true);
        $this->restore($owner, $organization, $archive->path);
        $restoreSeconds = microtime(true) - $started;

        fwrite(STDERR, sprintf("\n[S-08 timed exercise] driver=%s backup=%.2fs restore(incl. validation + pre-restore snapshot)=%.2fs\n", DB::getDriverName(), $backupSeconds, $restoreSeconds));
        $this->assertLessThan(60, $restoreSeconds);
    }

    private function restore(User $owner, Organization $organization, string $path): void
    {
        app(RestoreOrganizationBackupAction::class)->execute($owner, $organization, $path);
    }

    private function assertRefused(callable $restore, string $messagePart): void
    {
        try {
            $restore();
            $this->fail('Expected the restore to be refused.');
        } catch (OrganizationBackupException $exception) {
            $this->assertStringContainsString('Restauration refusée', $exception->getMessage());
            $this->assertStringContainsString($messagePart, $exception->getMessage());
        }
    }

    private function laterPayment(User $owner, Organization $organization, $account): Payment
    {
        $store = $organization->stores()->firstOrFail();
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '50.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();

        return $this->recordPayment($owner, $order, $account, '10.0000');
    }

    /** @return array{User, Organization, SalesOrder, FinancialAccount, Payment, Invoice} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $customer = $this->createCustomer($organization, 'Backup Customer');
        $variant = $this->createProduct($organization, 'Backup Product', 'BKP-1', ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');
        $order = $this->createDraftOrder($owner, $organization, $store, $customer);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '1']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $payment = $this->recordPayment($owner, $order, $account, $order->total_incl_tax);

        return [$owner, $organization, $order, $account, $payment, $invoice];
    }
}
