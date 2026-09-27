<?php

namespace Tests\Feature\Settings;

use App\Actions\OrganizationBackups\RestoreOrganizationBackupAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\User;
use App\Services\OrganizationBackups\OrganizationBackupException;
use App\Services\OrganizationBackups\OrganizationBackupExporter;
use App\Services\OrganizationBackups\OrganizationBackupValidator;
use Illuminate\Http\UploadedFile;
use Tests\Support\DocumentTestCase;
use ZipArchive;

class OrganizationBackupTest extends DocumentTestCase
{
    public function test_authorized_user_can_create_manual_backup(): void
    {
        [$owner] = $this->backupFixture();

        $this->actingAs($owner)
            ->post(route('organization-backups.store'))
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->assertDatabaseHas('audit_logs', ['event' => 'organization_backup.created']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'organization_backup.downloaded']);
    }

    public function test_unauthorized_backup_is_denied(): void
    {
        [$owner, $organization, $store] = $this->backupFixture();
        $sales = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $sales);

        $this->actingAs($sales)->post(route('organization-backups.store'))->assertForbidden();
    }

    public function test_valid_backup_validation_returns_summary(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);

        $result = app(OrganizationBackupValidator::class)->validatePath($archive->path, $organization);

        $this->assertSame($organization->id, $result['manifest']['source_organization_id']);
        $this->assertGreaterThanOrEqual(1, $result['summary']['products']);
        $this->assertGreaterThanOrEqual(1, $result['summary']['customers']);
    }

    public function test_corrupted_archive_is_rejected(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $path = storage_path('app/testing-corrupt.erpbackup');
        file_put_contents($path, 'not a zip');

        $this->expectException(OrganizationBackupException::class);
        app(OrganizationBackupValidator::class)->validatePath($path, $organization);
    }

    public function test_checksum_mismatch_is_rejected(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        $manifest = $archive->manifest;
        $manifest['checksum'] = str_repeat('0', 64);
        $this->replaceManifest($archive->path, $manifest);

        $this->expectException(OrganizationBackupException::class);
        app(OrganizationBackupValidator::class)->validatePath($archive->path, $organization);
    }

    public function test_unsupported_future_version_is_rejected(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        $manifest = $archive->manifest;
        $manifest['version'] = 999;
        $this->replaceManifest($archive->path, $manifest);

        $this->expectException(OrganizationBackupException::class);
        app(OrganizationBackupValidator::class)->validatePath($archive->path, $organization);
    }

    public function test_zip_slip_archive_is_rejected(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $path = storage_path('app/testing-zipslip.erpbackup');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('../evil.txt', 'x');
        $zip->addFromString('manifest.json', '{}');
        $zip->close();

        $this->expectException(OrganizationBackupException::class);
        app(OrganizationBackupValidator::class)->validatePath($path, $organization);
    }

    public function test_organization_a_backup_cannot_restore_into_organization_b(): void
    {
        [$ownerA, $organizationA] = $this->backupFixture();
        [$ownerB, $organizationB] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organizationA, $ownerA);

        $this->expectException(OrganizationBackupException::class);
        app(RestoreOrganizationBackupAction::class)->execute($ownerB, $organizationB, $archive->path);
    }

    public function test_successful_same_organization_restore_preserves_business_state(): void
    {
        [$owner, $organization, $store, $warehouse, $variant, $order, $invoice, $payment] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);

        Product::query()->where('organization_id', $organization->id)->update(['name' => 'Changed Product']);
        Customer::query()->where('organization_id', $organization->id)->update(['display_name' => 'Changed Customer']);

        app(RestoreOrganizationBackupAction::class)->execute($owner, $organization, $archive->path);

        $this->assertDatabaseHas('products', ['organization_id' => $organization->id, 'name' => 'Backup Product']);
        $this->assertDatabaseHas('customers', ['organization_id' => $organization->id, 'display_name' => 'Backup Customer']);
        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'total_incl_tax' => '1080.0000']);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'version' => 1, 'total_incl_tax' => '1080.0000']);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => '1080.0000']);
        $this->assertGreaterThan(0, InventoryMovement::query()->where('organization_id', $organization->id)->count());
    }

    public function test_missing_required_data_fails_safely(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $path = storage_path('app/testing-missing-data.erpbackup');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode([
            'format' => 'commerce-erp-organization-backup',
            'version' => 1,
            'source_organization_id' => $organization->id,
            'tables' => ['organizations'],
            'checksum' => str_repeat('0', 64),
        ]));
        $zip->close();

        $this->expectException(OrganizationBackupException::class);
        app(OrganizationBackupValidator::class)->validatePath($path, $organization);
    }

    public function test_tenant_secrets_are_marked_sensitive_and_audit_logs_do_not_store_payloads(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $mail = new \App\Models\OrganizationMailSetting;
        $mail->organization_id = $organization->id;
        $mail->sender_name = 'Secret';
        $mail->sender_email = 'secret@example.test';
        $mail->smtp_host = 'smtp.example.test';
        $mail->smtp_port = 465;
        $mail->smtp_encryption = 'ssl';
        $mail->smtp_username = 'secret@example.test';
        $mail->smtp_password = 'encrypted-secret';
        $mail->is_enabled = true;
        $mail->save();

        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);

        $this->assertArrayHasKey('organization_mail_settings', $archive->manifest['sensitive_columns']);
        $this->assertContains('smtp_password', $archive->manifest['sensitive_columns']['organization_mail_settings']);
        $this->assertDatabaseMissing('audit_logs', ['new_values->password' => 'encrypted-secret']);
    }

    public function test_restore_failure_does_not_leave_partial_ordinary_state(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $before = Product::query()->where('organization_id', $organization->id)->count();
        $path = storage_path('app/testing-bad-restore.erpbackup');
        file_put_contents($path, 'not a zip');

        try {
            app(RestoreOrganizationBackupAction::class)->execute($owner, $organization, $path);
            $this->fail('Restore should fail.');
        } catch (\Throwable) {
            $this->assertSame($before, Product::query()->where('organization_id', $organization->id)->count());
            $this->assertDatabaseHas('audit_logs', ['event' => 'organization_backup.restore_failed', 'organization_id' => $organization->id]);
        }
    }

    public function test_upload_validation_route_rejects_wrong_extension(): void
    {
        [$owner] = $this->backupFixture();

        $this->actingAs($owner)
            ->post(route('organization-backups.validate'), [
                'backup' => UploadedFile::fake()->create('backup.zip', 1),
            ])
            ->assertSessionHasErrors('backup');
    }

    /** @return array{User, \App\Models\Organization, \App\Models\Store, \App\Models\Warehouse, \App\Models\ProductVariant, \App\Models\SalesOrder, \App\Models\Invoice, Payment} */
    private function backupFixture(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $customer = $this->createCustomer($organization, 'Backup Customer');
        $variant = $this->createProduct($organization, 'Backup Product', 'BKP-1', [
            'default_sale_price' => '1000.0000',
            'tax_rate_id' => $tax->id,
        ])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');
        $order = $this->createDraftOrder($owner, $organization, $store, $customer);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, [
            'quantity' => '1',
            'discount_type' => 'percentage',
            'discount_value' => '10.0000',
        ]);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $payment = PaymentAllocation::query()->where('sales_order_id', $order->id)->with('payment')->firstOrFail()->payment;

        return [$owner, $organization, $store, $warehouse, $variant, $order, $invoice, $payment];
    }

    /** @param array<string, mixed> $manifest */
    private function replaceManifest(string $path, array $manifest): void
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->deleteName('manifest.json');
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
    }
}
