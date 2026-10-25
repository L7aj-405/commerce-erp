<?php

use App\Jobs\RecordQueueWorkerHeartbeatJob;
use App\Models\McpOperation;
use App\Models\McpOperationItem;
use App\Models\ProductImport;
use App\Models\SystemHealthHeartbeat;
use App\Models\TrustedTwoFactorDevice;
use App\Models\UserNotification;
use App\Models\Organization;
use App\Actions\Finance\GenerateRecurringFinanceObligationsAction;
use App\Services\Notifications\SystemHealthNotificationMonitor;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('catalog:cleanup-product-imports', function () {
    $count = ProductImport::query()->where('expires_at', '<', now())->delete();
    $this->info("Deleted {$count} expired product import staging records.");
})->purpose('Delete expired product import staging and history');

Artisan::command('organization-backups:cleanup-temporary-files', function () {
    $cutoff = now()->subHours(max(1, (int) config('organization-backups.temporary_file_ttl_hours', 24)))->getTimestamp();
    $deleted = 0;
    foreach (['tmp', 'validated', 'history', 'downloads', 'cloud-sync'] as $directory) {
        $path = storage_path('app/private/organization-backups/'.$directory);
        if (! is_dir($path)) {
            continue;
        }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isFile() && $file->getMTime() < $cutoff && @unlink($file->getPathname())) {
                $deleted++;
            }
        }
    }
    $preRestorePath = storage_path('app/private/organization-backups/pre-restore');
    $preRestoreCutoff = now()->subDays(max(1, (int) config('organization-backups.pre_restore_retention_days', 30)))->getTimestamp();
    if (is_dir($preRestorePath)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($preRestorePath, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'erpbackup'
                && $file->getMTime() < $preRestoreCutoff && @unlink($file->getPathname())) {
                $deleted++;
            }
        }
    }
    $this->info("Deleted {$deleted} expired organization backup temporary files.");
})->purpose('Delete expired private organization backup staging files');

Artisan::command('security:cleanup-trusted-devices', function () {
    $cutoff = now()->subDays(max(1, (int) config('two-factor.trusted_device_cleanup_days', 30)));
    $count = TrustedTwoFactorDevice::query()
        ->where(function ($query) use ($cutoff): void {
            $query->where('expires_at', '<', $cutoff)
                ->orWhere(fn ($revoked) => $revoked->whereNotNull('revoked_at')->where('revoked_at', '<', $cutoff));
        })
        ->delete();

    $this->info("Deleted {$count} expired or revoked trusted device records.");
})->purpose('Delete old expired or revoked trusted 2FA device records');

Artisan::command('finance:generate-recurring-obligations {--organization=}', function () {
    $month = now()->format('Y-m');
    $created = $existing = $skipped = $failed = 0;
    Organization::query()->where('status', 'active')
        ->when($this->option('organization'), fn ($query, $id) => $query->whereKey($id))
        ->orderBy('id')->chunkById(100, function ($organizations) use ($month, &$created, &$existing, &$skipped, &$failed): void {
            foreach ($organizations as $organization) {
                try {
                    $result = app(GenerateRecurringFinanceObligationsAction::class)->execute($organization, $month);
                    $created += $result['created']; $existing += $result['existing']; $skipped += $result['skipped'];
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->error("Organization {$organization->getKey()}: generation failed.");
                }
            }
        });
    $this->info("Recurring obligations: {$created} created, {$existing} existing, {$skipped} skipped, {$failed} organization(s) failed.");
    return $failed === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Generate the current month recurring Finance obligations idempotently');

Artisan::command('mcp:prune-operations', function () {
    $expired = McpOperation::query()->where('status', McpOperation::STATUS_PREVIEWED)->where('preview_expires_at', '<', now())
        ->update(['status' => McpOperation::STATUS_EXPIRED, 'failure_code' => 'preview_expired', 'failure_message' => 'The preview expired before execution.', 'updated_at' => now()]);

    // Per-row payloads are purged after the retention window; the operation
    // summary row (type, counters, attribution, timestamps) is kept, alongside
    // the operation-level and per-record audit log entries.
    $cutoff = now()->subDays(max(1, (int) config('mcp.operations.retention_days', 30)));
    $purged = 0;
    McpOperation::query()->whereNull('details_purged_at')
        ->where(fn ($query) => $query
            ->where(fn ($terminal) => $terminal->whereIn('status', McpOperation::TERMINAL_STATUSES)->where('updated_at', '<', $cutoff))
            ->orWhere(fn ($abandoned) => $abandoned->whereIn('status', [McpOperation::STATUS_VALIDATING, McpOperation::STATUS_PREVIEWED])->where('created_at', '<', $cutoff)))
        ->select(['id'])
        ->chunkById(100, function ($operations) use (&$purged) {
            foreach ($operations as $operation) {
                do {
                    $deleted = McpOperationItem::query()->where('mcp_operation_id', $operation->getKey())->limit(1000)->delete();
                } while ($deleted > 0);
                McpOperation::query()->whereKey($operation->getKey())->update(['details_purged_at' => now(), 'updated_at' => now()]);
                $purged++;
            }
        }, 'id');

    $this->info("MCP operations: {$expired} preview(s) expired, {$purged} operation detail set(s) purged.");
})->purpose('Expire stale MCP operation previews and purge per-row details past retention');

Artisan::command('mcp:prune-oauth', function () {
    // Codes live minutes; tokens are kept a week past their last possible use
    // so replay detection still sees recently rotated/revoked refresh tokens.
    $codes = \App\Models\McpOAuthAuthorizationCode::query()->where('expires_at', '<', now()->subDay())->delete();
    $tokens = \App\Models\McpOAuthToken::query()
        ->where('access_expires_at', '<', now()->subDays(7))
        ->where(fn ($query) => $query->whereNull('refresh_expires_at')->orWhere('refresh_expires_at', '<', now()->subDays(7)))
        ->delete();
    $this->info("MCP OAuth: {$codes} code(s) and {$tokens} token row(s) pruned.");
})->purpose('Delete expired MCP OAuth authorization codes and long-dead tokens');

Schedule::command('catalog:cleanup-product-imports')->daily();
Schedule::command('mcp:prune-oauth')->dailyAt('03:35')->withoutOverlapping();
Schedule::command('mcp:prune-operations')->dailyAt('03:20')->withoutOverlapping();
Schedule::command('organization-backups:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('organization-backups:cleanup-temporary-files')->hourly()->withoutOverlapping();
Schedule::command('security:cleanup-trusted-devices')->daily()->withoutOverlapping();
Schedule::command('finance:generate-recurring-obligations')->dailyAt('00:10')->withoutOverlapping();
// Correctness does not depend on the mutex: occurrences are claimed under row locks.
Schedule::command('follow-ups:notify-due')->everyFiveMinutes()->withoutOverlapping();
Schedule::call(fn () => SystemHealthHeartbeat::beat('scheduler'))
    ->name('system-health:scheduler-heartbeat')->everyMinute()->withoutOverlapping();
Schedule::job(new RecordQueueWorkerHeartbeatJob)
    ->name('system-health:queue-worker-heartbeat')->everyMinute();
Schedule::call(fn () => app(SystemHealthNotificationMonitor::class)->evaluateAll())
    ->name('notifications:system-health-transitions')->everyFiveMinutes()->withoutOverlapping();
Schedule::call(function () {
    UserNotification::query()->whereNotNull('read_at')->where('created_at', '<', now()->subDays(max(1, (int) config('notifications.retention_days', 90))))->delete();
    UserNotification::query()->whereNull('read_at')->where('severity', '!=', 'critical')
        ->where('created_at', '<', now()->subDays(max(1, (int) config('notifications.unread_retention_days', 180))))->delete();
})->name('notifications:cleanup')->daily()->withoutOverlapping();
