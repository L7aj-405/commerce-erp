<?php

namespace App\Jobs;

use App\Actions\WooCommerce\SyncWooCommerceProductsAction;
use App\Models\User;
use App\Models\WooCommerceIntegration;
use App\Models\WooCommerceSyncRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs a WooCommerce product synchronization off the HTTP request. Idempotent —
 * safe to re-dispatch. `ShouldBeUnique` plus the action's cache lock prevent two
 * concurrent runs for the same integration.
 */
class SyncWooCommerceProductsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

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

        $action->execute($integration, $actor, $this->mode);
    }

    public function failed(Throwable $exception): void
    {
        WooCommerceSyncRun::query()
            ->where('woocommerce_integration_id', $this->integrationId)
            ->where('status', WooCommerceSyncRun::STATUS_RUNNING)
            ->update([
                'status' => WooCommerceSyncRun::STATUS_FAILED,
                'message' => 'La tâche de synchronisation a échoué.',
                'completed_at' => now(),
            ]);
    }
}
