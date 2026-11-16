<?php

namespace App\Jobs;

use App\Models\SystemHealthHeartbeat;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordQueueWorkerHeartbeatJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 180;

    public function handle(): void
    {
        // The worker process reports ITS OWN build identity (its env, not the web app's), so
        // the health page can show whether web and worker run the same release. Non-secret only.
        SystemHealthHeartbeat::beat('queue_worker', [
            'queue' => $this->queue ?? 'default',
            'commit' => config('system-health.commit_sha') ?: null,
            'build_at' => config('system-health.build_at') ?: null,
        ]);
    }

    public function uniqueId(): string
    {
        return 'system-health-queue-worker';
    }
}
