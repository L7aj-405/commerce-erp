<?php

namespace Tests\Feature\Settings;

use App\Actions\OrganizationBackups\RestoreOrganizationBackupAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\WooCommerceIntegration;
use App\Services\OrganizationBackups\OrganizationBackupException;
use App\Services\OrganizationBackups\OrganizationBackupExporter;
use App\Services\OrganizationBackups\OrganizationBackupCryptography;
use App\Services\OrganizationBackups\OrganizationBackupRestorer;
use App\Services\OrganizationBackups\OrganizationBackupSchema;
use App\Services\OrganizationBackups\OrganizationBackupValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

    public function test_backup_is_authenticated_and_every_entry_is_encrypted(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive->path));
        $zip->setPassword(app(OrganizationBackupCryptography::class)->encryptionPassword());

        foreach (['manifest.json', ...array_map(
            fn (string $table) => "data/{$table}.jsonl",
            $archive->manifest['tables'],
        )] as $entry) {
            $stat = $zip->statName($entry);
            $this->assertIsArray($stat);
            $this->assertSame(ZipArchive::EM_AES_256, $stat['encryption_method'] ?? null);
        }

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue(app(OrganizationBackupCryptography::class)->verify($manifest));
        $zip->close();

        $raw = file_get_contents($archive->path);
        $this->assertStringNotContainsString('Backup Customer', $raw);
        $this->assertStringNotContainsString('Backup Product', $raw);
    }

    public function test_unencrypted_v2_archive_is_rejected_even_with_a_valid_signature_and_checksum(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        $this->setAllArchiveEncryptionMethods($archive->path, ZipArchive::EM_NONE);

        $this->expectException(OrganizationBackupException::class);
        app(OrganizationBackupValidator::class)->validatePath($archive->path, $organization);
    }

    public function test_legacy_zip_encryption_is_rejected_for_v2_archive(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        $this->assertTrue(defined(ZipArchive::class.'::EM_TRAD_PKWARE'));
        $this->setAllArchiveEncryptionMethods($archive->path, (int) constant(ZipArchive::class.'::EM_TRAD_PKWARE'));

        $this->expectException(OrganizationBackupException::class);
        app(OrganizationBackupValidator::class)->validatePath($archive->path, $organization);
    }

    public function test_mixed_encryption_methods_are_rejected_for_v2_archive(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        $this->assertTrue(defined(ZipArchive::class.'::EM_TRAD_PKWARE'));
        $weak = (int) constant(ZipArchive::class.'::EM_TRAD_PKWARE');
        $firstDataEntry = 'data/'.$archive->manifest['tables'][0].'.jsonl';

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive->path));
        $zip->setPassword(app(OrganizationBackupCryptography::class)->encryptionPassword());
        $this->assertTrue($zip->setEncryptionName($firstDataEntry, $weak));
        $this->assertTrue($zip->close());

        $this->expectException(OrganizationBackupException::class);
        app(OrganizationBackupValidator::class)->validatePath($archive->path, $organization);
    }

    public function test_unsigned_legacy_backup_is_rejected_by_default(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $path = storage_path('app/testing-unsigned-legacy.erpbackup');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode([
            'format' => 'commerce-erp-organization-backup',
            'version' => 1,
            'source_organization_id' => $organization->id,
            'tables' => [],
            'counts' => [],
            'checksum' => hash('sha256', ''),
        ]));
        $zip->close();

        $this->expectException(OrganizationBackupException::class);
        app(OrganizationBackupValidator::class)->validatePath($path, $organization);
    }

    public function test_explicit_legacy_mode_validates_old_archive_but_excludes_audit_history_from_restore(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $tables = ['organizations', 'audit_logs'];
        $entries = [];
        $hash = hash_init('sha256');
        $counts = [];
        foreach ($tables as $table) {
            $rows = $table === 'organizations'
                ? DB::table($table)->where('id', $organization->id)->get()
                : DB::table($table)->where('organization_id', $organization->id)->get();
            $contents = '';
            foreach ($rows as $row) {
                $json = json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $contents .= $json."\n";
                hash_update($hash, $table."\0".$json."\n");
            }
            $entries["data/{$table}.jsonl"] = $contents;
            $counts[$table] = $rows->count();
        }
        $manifest = [
            'format' => 'commerce-erp-organization-backup',
            'version' => 1,
            'source_organization_id' => $organization->id,
            'source_organization_name' => $organization->name,
            'tables' => $tables,
            'counts' => $counts,
            'checksum' => hash_final($hash),
        ];
        $path = storage_path('app/testing-controlled-legacy.erpbackup');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest));
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        config(['organization-backups.allow_legacy_unsigned_restore' => true]);

        $validated = app(OrganizationBackupValidator::class)->validatePath($path, $organization);

        $this->assertContains('organizations', $validated['manifest']['restore_tables']);
        $this->assertNotContains('audit_logs', $validated['manifest']['restore_tables']);
    }

    public function test_unexpected_archive_entry_is_rejected(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        $zip = new ZipArchive;
        $zip->open($archive->path);
        $zip->addFromString('unexpected.txt', 'not declared by the signed manifest');
        $zip->close();

        $this->expectException(OrganizationBackupException::class);
        app(OrganizationBackupValidator::class)->validatePath($archive->path, $organization);
    }

    public function test_archive_entry_limit_is_enforced_before_data_is_processed(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        config(['organization-backups.limits.entries' => 1]);

        $this->expectException(OrganizationBackupException::class);
        app(OrganizationBackupValidator::class)->validatePath($archive->path, $organization);
    }

    public function test_backup_uses_an_explicit_business_allowlist_and_excludes_credentials_and_audit_history(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);

        $this->assertSame(app(OrganizationBackupSchema::class)->tenantTables(), $archive->manifest['tables']);
        foreach ([
            'audit_logs', 'organization_mail_settings', 'woocommerce_integrations',
            'organization_cloud_backup_connections', 'memberships', 'roles',
        ] as $excludedTable) {
            $this->assertNotContains($excludedTable, $archive->manifest['tables']);
        }
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
        $currentOwner = User::factory()->create();
        $connection = DB::connection();
        $transactionLevel = $connection->transactionLevel();
        $pdoWasInTransaction = $connection->getPdo()->inTransaction();
        $sqliteDeferState = $connection->getDriverName() === 'sqlite'
            ? (int) $connection->scalar('PRAGMA defer_foreign_keys')
            : null;

        Product::query()->where('organization_id', $organization->id)->update(['name' => 'Changed Product']);
        Customer::query()->where('organization_id', $organization->id)->update(['display_name' => 'Changed Customer']);
        DB::table('organizations')->where('id', $organization->id)->update([
            'owner_id' => $currentOwner->id,
            'status' => 'suspended',
            'name' => 'Changed Organization',
        ]);

        app(RestoreOrganizationBackupAction::class)->execute($owner, $organization, $archive->path);

        $this->assertSame($transactionLevel, $connection->transactionLevel());
        $this->assertSame($pdoWasInTransaction, $connection->getPdo()->inTransaction());
        if ($sqliteDeferState !== null) {
            $this->assertSame($sqliteDeferState, (int) $connection->scalar('PRAGMA defer_foreign_keys'));
        }
        $this->assertDatabaseHas('products', ['organization_id' => $organization->id, 'name' => 'Backup Product']);
        $this->assertDatabaseHas('customers', ['organization_id' => $organization->id, 'display_name' => 'Backup Customer']);
        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'total_incl_tax' => '1080.0000']);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'version' => 1, 'total_incl_tax' => '1080.0000']);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => '1080.0000']);
        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'owner_id' => $currentOwner->id,
            'status' => 'suspended',
            'name' => $organization->name,
        ]);
        $this->assertGreaterThan(0, InventoryMovement::query()->where('organization_id', $organization->id)->count());
    }

    public function test_restore_fails_before_mutation_when_preserved_membership_references_store_absent_from_backup(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        $laterStore = $this->createStore($organization, $owner, 'Later Store');
        Product::query()->where('organization_id', $organization->id)->update(['name' => 'Must Stay Changed']);
        $transactionLevel = DB::connection()->transactionLevel();

        try {
            app(RestoreOrganizationBackupAction::class)->execute($owner, $organization, $archive->path);
            $this->fail('Restore preflight should reject a protected store membership.');
        } catch (OrganizationBackupException) {
            $this->assertDatabaseHas('stores', ['id' => $laterStore->id, 'organization_id' => $organization->id]);
            $this->assertDatabaseHas('store_memberships', ['store_id' => $laterStore->id, 'user_id' => $owner->id]);
            $this->assertDatabaseHas('products', ['organization_id' => $organization->id, 'name' => 'Must Stay Changed']);
            $this->assertSame($transactionLevel, DB::connection()->transactionLevel());
        }
    }

    public function test_failure_after_restore_transaction_begins_rolls_back_without_leaking_the_outer_transaction(): void
    {
        [$owner, $organization, $store] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        Product::query()->where('organization_id', $organization->id)->update(['name' => 'Must Survive Rollback']);
        $laterBrandId = DB::table('brands')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Must Survive Rollback',
            'slug' => 'must-survive-rollback',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $connection = DB::connection();
        $transactionLevel = $connection->transactionLevel();
        $pdoWasInTransaction = $connection->getPdo()->inTransaction();
        $sqliteDeferState = $connection->getDriverName() === 'sqlite'
            ? (int) $connection->scalar('PRAGMA defer_foreign_keys')
            : null;

        $failingSchema = new class extends OrganizationBackupSchema
        {
            public function hasOrganizationColumn(string $table): bool
            {
                if ($table === 'categories') {
                    throw new \RuntimeException('Forced failure after destructive restore work began.');
                }

                return parent::hasOrganizationColumn($table);
            }
        };
        $restorer = new OrganizationBackupRestorer(
            app(OrganizationBackupValidator::class),
            app(OrganizationBackupExporter::class),
            $failingSchema,
        );

        try {
            $restorer->restore($archive->path, $organization, $owner);
            $this->fail('Restore should fail after its nested transaction begins.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced failure after destructive restore work began.', $exception->getMessage());
        }

        $this->assertSame($transactionLevel, $connection->transactionLevel());
        $this->assertSame($pdoWasInTransaction, $connection->getPdo()->inTransaction());
        if ($sqliteDeferState !== null) {
            $this->assertSame($sqliteDeferState, (int) $connection->scalar('PRAGMA defer_foreign_keys'));
        }
        $this->assertDatabaseHas('stores', ['id' => $store->id, 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('store_memberships', ['store_id' => $store->id, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'active_store_id' => $store->id]);
        $this->assertDatabaseHas('products', ['organization_id' => $organization->id, 'name' => 'Must Survive Rollback']);
        $this->assertDatabaseHas('brands', ['id' => $laterBrandId, 'organization_id' => $organization->id]);

        $connection->beginTransaction();
        $this->assertSame($transactionLevel + 1, $connection->transactionLevel());
        $connection->rollBack($transactionLevel);
        $this->assertSame($transactionLevel, $connection->transactionLevel());
        $this->assertSame(1, (int) $connection->scalar('select 1'));
    }

    public function test_restore_fails_when_active_store_would_become_invalid(): void
    {
        [$owner, $organization] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        $laterStore = $this->createStore($organization, $owner, 'Active Later Store');
        DB::table('store_memberships')->where('store_id', $laterStore->id)->delete();
        $this->activate($owner, $organization, $laterStore);

        $this->expectException(OrganizationBackupException::class);
        app(RestoreOrganizationBackupAction::class)->execute($owner, $organization, $archive->path);
    }

    public function test_restore_fails_when_preserved_woocommerce_integration_references_new_warehouse(): void
    {
        [$owner, $organization, $store] = $this->backupFixture();
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        $laterWarehouse = $this->createWarehouse($organization, 'Later Warehouse');
        $integration = $this->createProtectedWooIntegration($organization->id, $store->id, $laterWarehouse->id);

        try {
            app(RestoreOrganizationBackupAction::class)->execute($owner, $organization, $archive->path);
            $this->fail('Restore preflight should reject a protected warehouse reference.');
        } catch (OrganizationBackupException) {
            $this->assertDatabaseHas('warehouses', ['id' => $laterWarehouse->id, 'organization_id' => $organization->id]);
            $this->assertDatabaseHas('woocommerce_integrations', ['id' => $integration->id, 'default_warehouse_id' => $laterWarehouse->id]);
        }
    }

    public function test_restore_accepts_woocommerce_children_when_preserved_parent_integration_still_exists(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->backupFixture();
        $integration = $this->createProtectedWooIntegration($organization->id, $store->id, $warehouse->id);
        $category = $this->createCategory($organization, 'Protected Mapping Category');
        $runId = DB::table('woocommerce_sync_runs')->insertGetId([
            'organization_id' => $organization->id,
            'woocommerce_integration_id' => $integration->id,
            'type' => 'products', 'mode' => 'full', 'status' => 'completed',
            'started_at' => now(), 'completed_at' => now(),
            'products_read' => 0, 'products_created' => 0, 'products_updated' => 0,
            'products_skipped' => 0, 'products_failed' => 0, 'variants_synced' => 0,
            'categories_synced' => 0, 'stock_adjustments' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mappingId = DB::table('woocommerce_category_mappings')->insertGetId([
            'organization_id' => $organization->id,
            'woocommerce_integration_id' => $integration->id,
            'remote_category_id' => 98765,
            'category_id' => $category->id,
            'remote_name' => 'Protected Mapping Category',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        DB::table('woocommerce_sync_runs')->where('id', $runId)->delete();
        DB::table('woocommerce_category_mappings')->where('id', $mappingId)->delete();

        app(RestoreOrganizationBackupAction::class)->execute($owner, $organization, $archive->path);

        $this->assertDatabaseHas('woocommerce_integrations', ['id' => $integration->id, 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('woocommerce_sync_runs', ['id' => $runId, 'woocommerce_integration_id' => $integration->id]);
        $this->assertDatabaseHas('woocommerce_category_mappings', ['id' => $mappingId, 'woocommerce_integration_id' => $integration->id]);
    }

    public function test_restore_rejects_woocommerce_child_state_when_preserved_parent_was_removed(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->backupFixture();
        $integration = $this->createProtectedWooIntegration($organization->id, $store->id, $warehouse->id);
        $category = $this->createCategory($organization, 'Removed Parent Mapping');
        DB::table('woocommerce_sync_runs')->insert([
            'organization_id' => $organization->id,
            'woocommerce_integration_id' => $integration->id,
            'type' => 'products', 'mode' => 'full', 'status' => 'completed',
            'started_at' => now(), 'completed_at' => now(),
            'products_read' => 0, 'products_created' => 0, 'products_updated' => 0,
            'products_skipped' => 0, 'products_failed' => 0, 'variants_synced' => 0,
            'categories_synced' => 0, 'stock_adjustments' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('woocommerce_category_mappings')->insert([
            'organization_id' => $organization->id,
            'woocommerce_integration_id' => $integration->id,
            'remote_category_id' => 12345,
            'category_id' => $category->id,
            'remote_name' => 'Removed Parent Mapping',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        $integration->delete();

        $this->expectException(OrganizationBackupException::class);
        app(RestoreOrganizationBackupAction::class)->execute($owner, $organization, $archive->path);
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

    public function test_tenant_credentials_and_audit_history_are_not_packaged(): void
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

        $this->assertNotContains('organization_mail_settings', $archive->manifest['tables']);
        $this->assertNotContains('audit_logs', $archive->manifest['tables']);
        $this->assertStringNotContainsString('encrypted-secret', file_get_contents($archive->path));
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

    public function test_restore_requires_a_recent_password_confirmation(): void
    {
        [$owner] = $this->backupFixture();

        $this->actingAs($owner)
            ->post(route('organization-backups.restore'), ['token' => 'invalid-token'])
            ->assertRedirect(route('password.confirm'));
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
        $account = $this->createFinancialAccount($organization);
        $payment = $this->recordPayment($owner, $order, $account, $order->total_incl_tax);

        return [$owner, $organization, $store, $warehouse, $variant, $order, $invoice, $payment];
    }

    /** @param array<string, mixed> $manifest */
    private function replaceManifest(string $path, array $manifest): void
    {
        unset($manifest['signature']);
        $manifest['signature'] = app(OrganizationBackupCryptography::class)->sign($manifest);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->setPassword(app(OrganizationBackupCryptography::class)->encryptionPassword());
        $zip->deleteName('manifest.json');
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256);
        $zip->close();
    }

    private function setAllArchiveEncryptionMethods(string $path, int $method): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $zip->setPassword(app(OrganizationBackupCryptography::class)->encryptionPassword());
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $this->assertIsString($name);
            $this->assertTrue($zip->setEncryptionName($name, $method));
        }
        $this->assertTrue($zip->close());
    }

    private function createProtectedWooIntegration(int $organizationId, int $storeId, int $warehouseId): WooCommerceIntegration
    {
        $integration = new WooCommerceIntegration;
        $integration->organization_id = $organizationId;
        $integration->name = 'Protected WooCommerce';
        $integration->store_url = 'https://shop.example.test';
        $integration->consumer_key = 'ck_protected';
        $integration->consumer_secret = 'secret-protected';
        $integration->default_store_id = $storeId;
        $integration->default_warehouse_id = $warehouseId;
        $integration->sync_stock = true;
        $integration->synced_product_count = 0;
        $integration->save();

        return $integration;
    }
}
