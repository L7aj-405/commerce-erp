<?php

namespace App\Jobs;

use App\Services\Mcp\Products\McpProductOperationProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Applies one bounded chunk (item id range) of a queued MCP product operation.
 * Re-running is safe: only items still `pending` are processed. Retries happen
 * only when the processor signals a transient infrastructure failure.
 */
class ProcessMcpProductOperationChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $operationId,
        public readonly int $fromItemId,
        public readonly int $toItemId,
    ) {
        $this->tries = max(1, (int) config('mcp.operations.max_item_attempts', 3)) + 1;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_map('intval', (array) config('mcp.operations.backoff_seconds', [10, 30, 90]));
    }

    public function handle(McpProductOperationProcessor $processor): void
    {
        $processor->processChunk($this->operationId, $this->fromItemId, $this->toItemId);
    }

    public function failed(?Throwable $exception): void
    {
        app(McpProductOperationProcessor::class)->abandonChunk($this->operationId, $this->fromItemId, $this->toItemId);
    }
}
