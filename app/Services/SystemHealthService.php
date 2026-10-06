<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationBackup;
use App\Models\OrganizationBackupCloudCopy;
use App\Models\OrganizationBackupSetting;
use App\Models\OrganizationMailSetting;
use App\Models\SystemHealthHeartbeat;
use App\Models\WooCommerceSyncRun;
use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SystemHealthService
{
    private const RANK = ['operational' => 0, 'unknown' => 1, 'degraded' => 2, 'critical' => 3];

    /** @return array<string, mixed> */
    public function snapshot(Organization $organization, bool $includeGlobalMetrics = false): array
    {
        $ttl = max(1, (int) config('system-health.cache_seconds', 10));

        $scope = $includeGlobalMetrics ? 'global' : 'tenant';

        return Cache::remember("system-health:v1:{$organization->getKey()}:{$scope}", $ttl, function () use ($organization, $includeGlobalMetrics) {
            $restricted = $this->check('unknown', 'Métrique globale réservée aux propriétaires privilégiés.');
            $checks = [
                'application' => $includeGlobalMetrics ? $this->application() : $restricted,
                'security' => $includeGlobalMetrics ? $this->securityConfiguration() : $restricted,
                'database' => $includeGlobalMetrics ? $this->database() : $restricted,
                'queue' => $includeGlobalMetrics ? $this->queue() : $restricted,
                'scheduler' => $includeGlobalMetrics ? $this->scheduler() : $restricted,
                'backups' => $this->backups($organization),
                'woocommerce' => $this->woocommerce($organization),
                'storage' => $includeGlobalMetrics ? $this->storage() : $restricted,
                'smtp' => $this->smtp($organization),
            ];
            $overall = collect($checks)->sortByDesc(fn (array $check) => self::RANK[$check['status']] ?? 1)->first()['status'] ?? 'unknown';

            return [
                'status' => $overall,
                'checked_at' => now()->toIso8601String(),
                'checks' => $checks,
                'failed_jobs' => $includeGlobalMetrics ? $this->recentFailedJobs() : [],
                'security_events' => $this->securityEvents($organization),
                'errors' => [
                    'available' => false,
                    'reason' => 'Les statuts HTTP 5xx et journaux applicatifs ne sont pas persistés sous une forme structurée et attribuable à une organisation.',
                ],
            ];
        });
    }

    /** @return array<string, mixed> */
    private function application(): array
    {
        $manifest = public_path('build/manifest.json');
        $debug = (bool) config('app.debug');

        return $this->check($debug ? 'degraded' : 'operational', $debug ? 'APP_DEBUG est activé.' : 'Configuration de production sûre.', [
            'environment' => app()->environment(),
            'debug_safe' => ! $debug,
            'version' => config('app.version') ?: config('system-health.app_version'),
            'commit' => config('system-health.commit_sha'),
            'build_at' => is_file($manifest) ? date(DATE_ATOM, filemtime($manifest)) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function securityConfiguration(): array
    {
        $production = app()->environment('production');
        $debugSafe = ! (bool) config('app.debug');
        $secureCookie = (bool) config('session.secure');
        $httpOnly = (bool) config('session.http_only');
        $appKeyConfigured = filled(config('app.key'));
        $backupSigningConfigured = filled(config('organization-backups.signing_key'));
        $backupEncryptionConfigured = filled(config('organization-backups.encryption_key'));

        $critical = $production && (! $debugSafe || ! $secureCookie || ! $httpOnly || ! $appKeyConfigured);
        $degraded = $production && (! $backupSigningConfigured || ! $backupEncryptionConfigured);
        $status = $critical ? 'critical' : ($degraded ? 'degraded' : 'operational');

        return $this->check($status, match ($status) {
            'critical' => 'Une configuration de sécurité obligatoire est absente ou dangereuse.',
            'degraded' => 'Les clés de sauvegarde obligatoires ne sont pas toutes configurées.',
            default => 'Configuration de sécurité attendue disponible.',
        }, [
            'debug_safe' => $debugSafe,
            'secure_session_cookie' => $secureCookie,
            'http_only_session_cookie' => $httpOnly,
            'app_key_configured' => $appKeyConfigured,
            'backup_signing_key_configured' => $backupSigningConfigured,
            'backup_encryption_key_configured' => $backupEncryptionConfigured,
        ]);
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        try {
            $start = hrtime(true);
            DB::select('SELECT 1');
            $latency = round((hrtime(true) - $start) / 1_000_000, 1);

            return $this->check('operational', 'Connexion disponible.', ['driver' => DB::getDriverName(), 'latency_ms' => $latency]);
        } catch (Throwable) {
            return $this->check('critical', 'La base de données ne répond pas.', ['driver' => config('database.default'), 'latency_ms' => null]);
        }
    }

    /** @return array<string, mixed> */
    private function queue(): array
    {
        $driver = (string) config('queue.default');
        if ($driver !== 'database' || ! Schema::hasTable('jobs')) {
            return $this->check('unknown', 'Le pilote de file ne fournit pas de statistiques locales fiables.', ['driver' => $driver]);
        }

        $waiting = DB::table('jobs')->count();
        $oldest = DB::table('jobs')->min('created_at');
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $heartbeat = SystemHealthHeartbeat::query()->find('queue_worker');
        $fresh = $heartbeat?->last_seen_at?->gte(now()->subMinutes(max(1, (int) config('system-health.queue_heartbeat_stale_minutes')))) ?? false;
        $status = $fresh ? ($failed > 0 ? 'degraded' : 'operational') : ($waiting > 0 ? 'critical' : 'degraded');
        $message = $fresh ? 'Le worker traite les sondes de santé.' : ($waiting > 0 ? 'Aucun worker récent alors que des tâches attendent.' : 'Aucune sonde worker récente.');

        return $this->check($status, $message, [
            'driver' => $driver, 'waiting_jobs' => $waiting, 'failed_jobs' => $failed,
            'oldest_pending_age_seconds' => $oldest ? max(0, now()->timestamp - (int) $oldest) : null,
            'last_processed_at' => $heartbeat?->last_seen_at?->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    private function scheduler(): array
    {
        $heartbeat = SystemHealthHeartbeat::query()->find('scheduler');
        if (! $heartbeat?->last_seen_at) return $this->check('degraded', 'Aucun heartbeat du scheduler reçu.', ['last_seen_at' => null, 'expected_cadence_seconds' => 60]);
        $fresh = $heartbeat->last_seen_at->gte(now()->subMinutes(max(1, (int) config('system-health.scheduler_heartbeat_stale_minutes'))));

        return $this->check($fresh ? 'operational' : 'degraded', $fresh ? 'Le scheduler s’exécute normalement.' : 'Le heartbeat du scheduler est ancien.', [
            'last_seen_at' => $heartbeat->last_seen_at->toIso8601String(), 'expected_cadence_seconds' => 60,
        ]);
    }

    /** @return array<string, mixed> */
    private function backups(Organization $organization): array
    {
        $base = OrganizationBackup::query()->where('organization_id', $organization->getKey());
        $success = (clone $base)->where('status', OrganizationBackup::STATUS_COMPLETED)->latest('completed_at')->first();
        $failure = (clone $base)->where('status', OrganizationBackup::STATUS_FAILED)->latest('completed_at')->first();
        $setting = OrganizationBackupSetting::query()->where('organization_id', $organization->getKey())->first();
        $cloudFailure = OrganizationBackupCloudCopy::query()->where('organization_id', $organization->getKey())
            ->where('status', OrganizationBackupCloudCopy::STATUS_FAILED)->latest('updated_at')->first();
        $age = $success?->completed_at?->diffInHours(now());
        $stale = $setting?->enabled && ($age === null || $age > max(1, (int) config('system-health.backup_stale_hours')));
        $repeatedFailures = (clone $base)->where('status', OrganizationBackup::STATUS_FAILED)
            ->when($success?->completed_at, fn ($query, $date) => $query->where('created_at', '>', $date))->count();
        $failureIsCurrent = $failure && (! $success?->completed_at || ($failure->completed_at ?? $failure->updated_at)->gt($success->completed_at));
        $cloudFailureIsCurrent = $cloudFailure && (! $success?->completed_at || $cloudFailure->updated_at->gt($success->completed_at));
        $status = $repeatedFailures >= 3 && $stale ? 'critical' : (($stale || $failureIsCurrent || $cloudFailureIsCurrent) ? 'degraded' : 'operational');

        return $this->check($status, $stale ? 'La dernière sauvegarde réussie est absente ou trop ancienne.' : 'État des sauvegardes disponible.', [
            'scheduled_enabled' => (bool) $setting?->enabled,
            'latest_success_at' => $success?->completed_at?->toIso8601String(),
            'latest_failure_at' => ($failure?->completed_at ?? $failure?->updated_at)?->toIso8601String(),
            'latest_failure' => $failure ? SensitiveDataRedactor::text((string) $failure->failure_message, 240) : null,
            'latest_success_age_hours' => $age,
            'cloud_failure_at' => $cloudFailure?->updated_at?->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    private function woocommerce(Organization $organization): array
    {
        $base = WooCommerceSyncRun::query()->where('organization_id', $organization->getKey());
        $latest = (clone $base)->latest('id')->first();
        $running = (clone $base)->where('status', WooCommerceSyncRun::STATUS_RUNNING)->latest('id')->first();
        $success = (clone $base)->whereIn('status', [WooCommerceSyncRun::STATUS_COMPLETED, WooCommerceSyncRun::STATUS_COMPLETED_WITH_ERRORS])->latest('completed_at')->first();
        $failure = (clone $base)->where('status', WooCommerceSyncRun::STATUS_FAILED)->latest('completed_at')->first();
        $stuck = $running?->started_at?->lt(now()->subMinutes(max(1, (int) config('system-health.woocommerce_stale_minutes')))) ?? false;
        $status = $stuck || in_array($latest?->status, [WooCommerceSyncRun::STATUS_FAILED, WooCommerceSyncRun::STATUS_COMPLETED_WITH_ERRORS], true) ? 'degraded' : 'operational';

        return $this->check($status, $stuck ? 'Une synchronisation semble bloquée.' : ($latest ? 'Dernière synchronisation connue.' : 'Aucune synchronisation enregistrée.'), [
            'latest' => $this->syncSummary($latest), 'running' => $this->syncSummary($running),
            'last_success_at' => $success?->completed_at?->toIso8601String(), 'last_failure_at' => $failure?->completed_at?->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    private function storage(): array
    {
        $total = @disk_total_space(storage_path());
        $free = @disk_free_space(storage_path());
        if (! is_numeric($total) || ! is_numeric($free) || $total <= 0) return $this->check('unknown', 'Statistiques disque indisponibles.');
        $used = $total - $free;
        $percent = round(($used / $total) * 100, 1);
        $status = $this->classifyDiskUsage($percent);

        return $this->check($status, 'Utilisation du volume hébergeant le stockage applicatif.', ['total_bytes' => (int) $total, 'used_bytes' => (int) $used, 'free_bytes' => (int) $free, 'used_percent' => $percent]);
    }

    public function classifyDiskUsage(float $percent): string
    {
        return $percent >= (float) config('system-health.disk_critical_percent')
            ? 'critical'
            : ($percent >= (float) config('system-health.disk_warning_percent') ? 'degraded' : 'operational');
    }

    /** @return array<string, mixed> */
    private function smtp(Organization $organization): array
    {
        $setting = OrganizationMailSetting::query()->where('organization_id', $organization->getKey())->first();
        if (! $setting) return $this->check('unknown', 'SMTP non configuré.', ['configured' => false]);
        $status = $setting->last_test_ok === false ? 'degraded' : ($setting->last_test_ok === true ? 'operational' : 'unknown');

        return $this->check($status, $setting->last_test_ok === true ? 'Dernier test SMTP réussi.' : ($setting->last_test_ok === false ? 'Dernier test SMTP échoué.' : 'SMTP configuré mais non testé.'), [
            'configured' => $setting->isUsable(), 'enabled' => $setting->is_enabled,
            'last_test_ok' => $setting->last_test_ok, 'last_tested_at' => $setting->last_tested_at?->toIso8601String(),
            'last_test_message' => $setting->last_test_message ? SensitiveDataRedactor::text($setting->last_test_message, 240) : null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function recentFailedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) return [];
        return DB::table('failed_jobs')->latest('failed_at')->limit(10)->get(['uuid', 'connection', 'queue', 'failed_at'])
            ->map(fn ($job) => ['uuid' => $job->uuid, 'connection' => $job->connection, 'queue' => $job->queue, 'failed_at' => $job->failed_at])->all();
    }

    /** @return list<array<string, mixed>> */
    private function securityEvents(Organization $organization): array
    {
        return AuditLog::query()->where('organization_id', $organization->getKey())
            ->where(fn ($query) => $query->where('event', 'like', 'auth.%')->orWhere('event', 'like', 'two_factor.%'))
            ->with('actor:id,name,email')->latest('id')->limit(10)->get()
            ->map(fn (AuditLog $log) => ['id' => $log->id, 'event' => $log->event, 'actor' => $log->actor?->only(['id', 'name', 'email']), 'created_at' => $log->created_at?->toIso8601String()])->all();
    }

    /** @return array<string, mixed>|null */
    private function syncSummary(?WooCommerceSyncRun $run): ?array
    {
        if (! $run) return null;
        return ['id' => $run->id, 'status' => $run->status, 'started_at' => $run->started_at?->toIso8601String(), 'completed_at' => $run->completed_at?->toIso8601String(),
            'duration_seconds' => $run->started_at && $run->completed_at ? $run->started_at->diffInSeconds($run->completed_at) : null,
            'products_read' => $run->products_read, 'products_created' => $run->products_created, 'products_updated' => $run->products_updated,
            'products_skipped' => $run->products_skipped, 'products_failed' => $run->products_failed];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function check(string $status, string $message, array $data = []): array
    {
        return ['status' => $status, 'message' => $message, 'data' => $data];
    }
}
