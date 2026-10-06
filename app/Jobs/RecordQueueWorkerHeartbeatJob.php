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
        SystemHealthHeartbeat::beat('queue_worker', ['queue' => $this->queue ?? 'default']);
    }

    public function uniqueId(): string
    {
        return 'system-health-queue-worker';
    }
}
