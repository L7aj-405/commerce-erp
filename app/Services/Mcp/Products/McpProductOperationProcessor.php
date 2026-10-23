<?php

namespace App\Services\Mcp\Products;

use App\Actions\Catalog\ArchiveProductAction;
use App\Actions\Catalog\UpdateProductAction;
use App\Actions\Catalog\UpdateProductPurchasePriceAction;
use App\Actions\Catalog\UpdateVariantAction;
use App\Exceptions\Mcp\McpOperationItemFailure;
use App\Exceptions\Mcp\McpTransientOperationException;
use App\Models\McpOperation;
use App\Models\McpOperationItem;
use App\Services\Mcp\McpConnectionRuntime;
use App\Services\Mcp\McpExecutionContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Applies a queued chunk of a previewed operation. Each item runs in its own
 * bounded transaction through the authoritative catalog Actions, so one
 * failure never rolls back unrelated items. Completion is written in the same
 * transaction as the mutation, which keeps completed items idempotent.
 */
final class McpProductOperationProcessor
{
    use DetectsConcurrencyErrors, DetectsLostConnections;

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly McpConnectionRuntime $runtime,
        private readonly McpProductChangeService $changes,
        private readonly McpProductOperationService $operations,
        private readonly UpdateProductAction $updateProduct,
        private readonly UpdateVariantAction $updateVariant,
        private readonly UpdateProductPurchasePriceAction $updateCost,
        private readonly ArchiveProductAction $archiveProduct,
    ) {}

    public function processChunk(string $operationId, int $fromItemId, int $toItemId): void
    {
        $operation = McpOperation::query()->find($operationId);
        if (! $operation || in_array($operation->status, McpOperation::TERMINAL_STATUSES, true)) return;

        try {
            $failure = $this->runtime->bind($operation->mcp_connection_id);
            if ($failure === null) {
                foreach ($this->operations->scopesFor($operation) as $scope) {
                    if (! $this->context->allows($scope)) {
                        $failure = 'forbidden';
                        break;
                    }
                }
            }

            if ($failure !== null) {
                $this->failPending($operation, $fromItemId, $toItemId, $failure);
            } else {
                McpOperation::query()->whereKey($operation->getKey())->where('status', McpOperation::STATUS_QUEUED)
                    ->update(['status' => McpOperation::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
                $itemIds = $this->pendingQuery($operation, $fromItemId, $toItemId)->orderBy('ordinal')->pluck('id');
                foreach ($itemIds as $itemId) {
                    $this->processItem((int) $itemId);
                }
            }

            $this->finalize($operation);
        } finally {
            $this->runtime->release();
        }
    }

    /** Called when a chunk job exhausts its queue attempts. */
    public function abandonChunk(string $operationId, int $fromItemId, int $toItemId): void
    {
        $operation = McpOperation::query()->find($operationId);
        if (! $operation || in_array($operation->status, McpOperation::TERMINAL_STATUSES, true)) return;

        try {
            $this->failPending($operation, $fromItemId, $toItemId, 'processing_failed');
            $this->finalize($operation);
        } finally {
            $this->runtime->release();
        }
    }

    private function processItem(int $itemId): void
    {
        // Counted outside the item transaction so a rolled-back attempt is still recorded.
        McpOperationItem::query()->whereKey($itemId)->where('status', McpOperationItem::STATUS_PENDING)->increment('attempts');

        try {
            DB::transaction(function () use ($itemId) {
                $item = McpOperationItem::query()->whereKey($itemId)->lockForUpdate()->first();
                if (! $item || $item->status !== McpOperationItem::STATUS_PENDING) return;

                $evaluation = $this->changes->evaluate($item->target_type, (int) $item->target_id, $item->input_changes ?? [], lock: true);
                if ($evaluation['forbidden'] !== []) throw new McpOperationItemFailure('forbidden_fields');
                if ($evaluation['woo'] !== []) throw new McpOperationItemFailure('woocommerce_managed_fields');
                if (! hash_equals((string) $item->expected_version, $evaluation['version'])
                    || ! hash_equals((string) $item->expected_state_hash, $evaluation['state_hash'])
                    || ! hash_equals((string) $item->proposed_hash, $evaluation['proposed_hash'])) {
                    throw new McpOperationItemFailure('stale_target');
                }

                $updated = $this->apply($item->target_type, $evaluation['model'], $evaluation['proposed']);
                $item->forceFill([
                    'status' => McpOperationItem::STATUS_SUCCEEDED,
                    'result' => ['updated_at' => $updated->updated_at?->toIso8601String()],
                    'error_code' => null,
                    'error_message' => null,
                    'processed_at' => now(),
                ])->save();
            });
        } catch (McpOperationItemFailure $failure) {
            $this->failItem($itemId, $failure->reason);
        } catch (ValidationException $exception) {
            $first = collect($exception->errors())->flatten()->first();
            $this->failItem($itemId, 'validation_failed', is_string($first) ? mb_substr($first, 0, 255) : null);
        } catch (UniqueConstraintViolationException) {
            $this->failItem($itemId, 'uniqueness_violation');
        } catch (ModelNotFoundException) {
            $this->failItem($itemId, 'target_not_found');
        } catch (AuthorizationException) {
            $this->failItem($itemId, 'forbidden');
        } catch (Throwable $exception) {
            if ($this->causedByConcurrencyError($exception) || $this->causedByLostConnection($exception)) {
                $attempts = (int) McpOperationItem::query()->whereKey($itemId)->value('attempts');
                if ($attempts < (int) config('mcp.operations.max_item_attempts', 3)) {
                    throw new McpTransientOperationException('transient_failure', previous: $exception);
                }
                $this->failItem($itemId, 'transient_failure_exhausted');

                return;
            }

            Log::warning('MCP product operation item failed.', ['item_id' => $itemId, 'exception_type' => $exception::class]);
            $this->failItem($itemId, 'internal_error');
        }
    }

    /** @param array<string, mixed> $proposed */
    private function apply(string $targetType, Model $model, array $proposed): Model
    {
        $actor = $this->context->user();

        return match ($targetType) {
            'product' => $this->updateProduct->execute($actor, $model, $proposed),
            'variant' => $this->updateVariant->execute($actor, $model, [...$proposed, 'purchase_price' => $model->purchase_price]),
            'purchase_price' => $this->updateCost->execute($actor, $model, (string) $proposed['purchase_price']),
            'archive_product' => (function () use ($actor, $model) {
                $this->archiveProduct->execute($actor, $model);

                return $model->refresh();
            })(),
            default => throw new McpOperationItemFailure('invalid_row'),
        };
    }

    private function failItem(int $itemId, string $code, ?string $message = null): void
    {
        McpOperationItem::query()->whereKey($itemId)->where('status', McpOperationItem::STATUS_PENDING)->update([
            'status' => McpOperationItem::STATUS_FAILED,
            'error_code' => $code,
            'error_message' => $message ?? McpProductOperationService::message($code),
            'processed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function failPending(McpOperation $operation, int $fromItemId, int $toItemId, string $code): void
    {
        $this->pendingQuery($operation, $fromItemId, $toItemId)->update([
            'status' => McpOperationItem::STATUS_FAILED,
            'error_code' => $code,
            'error_message' => McpProductOperationService::message($code),
            'processed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function pendingQuery(McpOperation $operation, int $fromItemId, int $toItemId)
    {
        return McpOperationItem::query()
            ->where('mcp_operation_id', $operation->getKey())
            ->where('status', McpOperationItem::STATUS_PENDING)
            ->whereBetween('id', [$fromItemId, $toItemId]);
    }

    private function finalize(McpOperation $operation): void
    {
        if ($operation->items()->where('status', McpOperationItem::STATUS_PENDING)->exists()) return;

        $counts = $operation->items()->whereIn('status', [McpOperationItem::STATUS_SUCCEEDED, McpOperationItem::STATUS_FAILED])
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $succeeded = (int) ($counts[McpOperationItem::STATUS_SUCCEEDED] ?? 0);
        $failed = (int) ($counts[McpOperationItem::STATUS_FAILED] ?? 0);
        $status = $failed === 0 ? McpOperation::STATUS_COMPLETED : ($succeeded === 0 ? McpOperation::STATUS_FAILED : McpOperation::STATUS_PARTIAL);

        // The status guard makes finalization single-winner across concurrent chunks.
        $won = McpOperation::query()->whereKey($operation->getKey())
            ->whereIn('status', [McpOperation::STATUS_QUEUED, McpOperation::STATUS_RUNNING])
            ->update([
                'status' => $status,
                'succeeded_count' => $succeeded,
                'failed_count' => $failed,
                'failure_code' => $status === McpOperation::STATUS_FAILED ? 'all_items_failed' : null,
                'failure_message' => $status === McpOperation::STATUS_FAILED ? McpProductOperationService::message('all_items_failed') : null,
                'started_at' => DB::raw('coalesce(started_at, current_timestamp)'),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($won) {
            if (! $this->context->isBound()) $this->runtime->attribute($operation->mcp_connection_id);
            $this->operations->auditOperation('mcp.product_operation_finished', $operation->refresh());
        }
    }
}
