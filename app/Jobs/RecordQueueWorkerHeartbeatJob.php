<?php

namespace App\Jobs;

use App\Models\SystemHealthHeartbeat;
use App\Support\ReleaseIdentity;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecordQueueWorkerHeartbeatJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 180;

    /**
     * True when an unprocessed probe already waits in the database queue. The scheduler skips
     * dispatching another one then: with the worker down, a probe every minute (unique lock
     * expires after 3 min) piled up hundreds of identical jobs. A single waiting probe is enough:
     * the moment a worker starts it processes it and records a fresh, truthful heartbeat.
     */
    public static function alreadyPending(): bool
    {
        try {
            if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
                return false;
            }

            return DB::table('jobs')->where('queue', (string) config('queue.connections.database.queue', 'default'))
                ->where('payload', 'like', '%RecordQueueWorkerHeartbeatJob%')->exists();
        } catch (\Throwable) {
            return false; // never block monitoring on a lookup failure: dispatch as before
        }
    }

    public function handle(): void
    {
        // Read from the live process environment at probe time (see ReleaseIdentity): a cached
        // config can be frozen without the variable the platform injected into this worker.
        // The worker process reports ITS OWN build identity (its env, not the web app's), so
        // the health page can show whether web and worker run the same release. Non-secret only.
        SystemHealthHeartbeat::beat('queue_worker', [
            'queue' => $this->queue ?? 'default',
            'commit' => ReleaseIdentity::commit(),
            'build_at' => ReleaseIdentity::buildAt(),
        ]);
    }

    public function uniqueId(): string
    {
        return 'system-health-queue-worker';
    }
}
