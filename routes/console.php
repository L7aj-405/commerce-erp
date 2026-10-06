<?php

use App\Jobs\RecordQueueWorkerHeartbeatJob;
use App\Models\ProductImport;
use App\Models\SystemHealthHeartbeat;
use App\Models\UserNotification;
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

Schedule::command('catalog:cleanup-product-imports')->daily();
Schedule::command('organization-backups:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('organization-backups:cleanup-temporary-files')->hourly()->withoutOverlapping();
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
