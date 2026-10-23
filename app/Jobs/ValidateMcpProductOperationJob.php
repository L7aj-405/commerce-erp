<?php

namespace App\Jobs;

use App\Models\McpOperation;
use App\Services\Mcp\McpConnectionRuntime;
use App\Services\Mcp\Products\McpProductOperationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Validates and previews a large MCP product operation off the HTTP request.
 * Validation never mutates catalog data and is safe to re-run.
 */
class ValidateMcpProductOperationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $operationId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30];
    }

    public function handle(McpConnectionRuntime $runtime, McpProductOperationService $operations): void
    {
        $operation = McpOperation::query()->find($this->operationId);
        if (! $operation || $operation->status !== McpOperation::STATUS_VALIDATING) return;

        try {
            $failure = $runtime->bind($operation->mcp_connection_id);
            if ($failure !== null) {
                $this->markFailed($operation, $failure, McpProductOperationService::message($failure));

                return;
            }
            try {
                $operations->validate($operation);
            } catch (AuthorizationException) {
                $this->markFailed($operation, 'forbidden', McpProductOperationService::message('forbidden'));
            }
        } finally {
            $runtime->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $operation = McpOperation::query()->find($this->operationId);
        if ($operation) app(McpProductOperationService::class)->failValidation($operation);
    }

    private function markFailed(McpOperation $operation, string $code, string $message): void
    {
        McpOperation::query()->whereKey($operation->getKey())->where('status', McpOperation::STATUS_VALIDATING)->update([
            'status' => McpOperation::STATUS_FAILED,
            'failure_code' => $code,
            'failure_message' => $message,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
