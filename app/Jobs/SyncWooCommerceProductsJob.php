<?php

namespace App\Jobs;

use App\Actions\WooCommerce\SyncWooCommerceProductsAction;
use App\Models\User;
use App\Models\WooCommerceIntegration;
use App\Models\WooCommerceSyncRun;
use App\Services\Notifications\OperationalNotificationProducer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs a WooCommerce product synchronization off the HTTP request. Idempotent —
 * safe to re-dispatch. `ShouldBeUnique` plus the action's cache lock prevent two
 * concurrent runs for the same integration.
 *
 * Retries live in ONE place: the WooCommerce client retries a throttled /
 * failing request with Retry-After-aware backoff inside the run (bounded by
 * `woocommerce.max_total_backoff_seconds`, far below `$timeout`). The job
 * itself is never retried by the queue ($tries = 1): a retried job would
 * restart a whole sync on top of a still-throttled store — exactly the request
 * storm to avoid. Timing contract: backoff budget (900 s) + requests < $timeout
 * (3600 s) < queue `retry_after` (3900 s) ≤ $uniqueFor / action lock (3900 s).
 */
class SyncWooCommerceProductsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3900;

    public function __construct(
        public readonly int $integrationId,
        public readonly int $actorId,
        public readonly string $mode = 'full',
    ) {}

    public function uniqueId(): string
    {
        return 'woocommerce-sync:'.$this->integrationId;
    }

    public function handle(SyncWooCommerceProductsAction $action): void
    {
        $integration = WooCommerceIntegration::query()->find($this->integrationId);
        $actor = User::query()->find($this->actorId);

        if (! $integration || ! $actor) {
            return;
        }

        try {
            $action->execute($integration, $actor, $this->mode);
        } catch (ValidationException) {
            // Another run already holds the integration lock: this duplicate is a
            // no-op. It must not fail — failed() would end the OTHER, live run.
            Log::info('woocommerce.sync_skipped_already_running', ['integration_id' => $this->integrationId]);
        }
    }

    /**
     * The worker died, timed out or the action threw unexpectedly: the action's
     * `finally` may never have run. Close the live run and free the lock so the
     * operator can restart (restart is idempotent).
     */
    public function failed(Throwable $exception): void
    {
        WooCommerceSyncRun::query()
            ->where('woocommerce_integration_id', $this->integrationId)
            ->where('status', WooCommerceSyncRun::STATUS_RUNNING)
            ->update([
                'status' => WooCommerceSyncRun::STATUS_FAILED,
                'phase' => WooCommerceSyncRun::PHASE_FAILED,
                'failure_reason' => 'job_failed',
                'message' => 'La tâche de synchronisation a été interrompue (délai dépassé ou arrêt du worker). Relancez-la : elle reprend depuis le début sans créer de doublons.',
                'completed_at' => now(),
            ]);

        Cache::lock('woocommerce-sync:'.$this->integrationId)->forceRelease();

        $run = WooCommerceSyncRun::query()->where('woocommerce_integration_id', $this->integrationId)->latest('id')->first();
        $integration = WooCommerceIntegration::query()->with('organization')->find($this->integrationId);
        if ($run && $integration?->organization) {
            app(OperationalNotificationProducer::class)->wooCommerce($integration->organization, $run);
        }
    }
}
