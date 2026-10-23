<?php

namespace App\Services\Mcp\Products;

use App\Jobs\ProcessMcpProductOperationChunkJob;
use App\Jobs\ValidateMcpProductOperationJob;
use App\Models\McpOperation;
use App\Models\McpOperationItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuditLogger;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * MCP-4 bulk product operations: prepare → validate → preview → execute.
 * Nothing in this class mutates catalog data; execution only queues
 * ProcessMcpProductOperationChunkJob, which applies the authoritative Actions.
 */
final class McpProductOperationService
{
    public const SCOPES = [
        McpOperation::TYPE_PRODUCT_UPDATE => 'products.update',
        McpOperation::TYPE_PURCHASE_PRICE_UPDATE => 'product_cost.manage',
        McpOperation::TYPE_PRODUCT_ARCHIVE => 'products.archive',
    ];

    private const ROW_KEYS = [
        McpOperation::TYPE_PRODUCT_UPDATE => ['target_type', 'target_id', 'sku', 'changes'],
        McpOperation::TYPE_PURCHASE_PRICE_UPDATE => ['variant_id', 'sku', 'purchase_price'],
        McpOperation::TYPE_PRODUCT_ARCHIVE => ['product_id', 'sku'],
    ];

    private const DECIMAL_FIELDS = ['purchase_price', 'public_price_ttc', 'unit_price_ht', 'regular_sale_price', 'promotional_sale_price', 'default_sale_price'];
    private const IDENTIFIER_FIELDS = ['sku', 'reference', 'barcode'];
    private const DECIMAL_PATTERN = '/\A\d{1,15}(?:\.\d{1,4})?\z/';

    public const MESSAGES = [
        'invalid_row' => 'The row shape is invalid for this operation.',
        'invalid_decimal' => 'Decimal values must be strings with at most 4 fractional digits.',
        'target_not_found' => 'The target was not found in this organization.',
        'validation_failed' => 'The resulting record is invalid.',
        'forbidden_fields' => 'The row contains fields that cannot be changed by this operation.',
        'woocommerce_managed_fields' => 'The row changes fields owned by WooCommerce.',
        'duplicate_row' => 'An identical row for the same target appears earlier in the request.',
        'duplicate_target' => 'The same target appears in several rows with different changes.',
        'duplicate_identifier_in_request' => 'Several rows assign the same SKU, reference, or barcode.',
        'stale_target' => 'The target changed after the preview was computed.',
        'uniqueness_violation' => 'The change conflicts with an existing unique identifier.',
        'forbidden' => 'The connection is no longer authorized for this operation.',
        'connection_missing' => 'The MCP connection no longer exists.',
        'connection_inactive' => 'The MCP connection is revoked or expired.',
        'organization_inactive' => 'The organization is not active.',
        'mcp_disabled' => 'AI/MCP connections are disabled for this organization.',
        'membership_inactive' => 'The owning user no longer has active organization access.',
        'transient_failure_exhausted' => 'The record could not be processed after repeated transient failures.',
        'processing_failed' => 'The record could not be processed.',
        'internal_error' => 'The record could not be processed.',
        'preview_stale' => 'One or more targets changed after the preview was computed.',
        'preview_expired' => 'The preview expired before execution.',
        'validation_internal_error' => 'The operation could not be validated.',
        'all_items_failed' => 'No record could be applied.',
    ];

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly McpProductChangeService $changes,
        private readonly McpIdempotencyService $canonical,
        private readonly AuditLogger $audit,
    ) {}

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? self::MESSAGES['internal_error'];
    }

    /** @param list<mixed> $rows @return array<string, mixed> */
    public function prepare(string $type, array $rows, string $idempotencyKey): array
    {
        $scope = self::SCOPES[$type] ?? throw new InvalidArgumentException('invalid_operation_type');
        $this->context->authorize('products.view');
        $this->context->authorize($scope);
        $key = trim($idempotencyKey);
        if ($key === '' || mb_strlen($key) > 128) throw new InvalidArgumentException('invalid_idempotency_key');
        if ($rows === [] || ! array_is_list($rows)) throw new InvalidArgumentException('rows_required');
        if (count($rows) > (int) config('mcp.operations.max_items', 5000)) throw new InvalidArgumentException('too_many_rows');

        $connection = $this->context->connectionOrFail();
        $requestHash = hash('sha256', $this->canonical->canonicalJson(['type' => $type, 'rows' => $rows]));
        $lock = Cache::lock('mcp:operation:'.hash('sha256', $connection->getKey().'|'.$key), 60);

        [$operation, $replay] = $lock->block(5, function () use ($connection, $key, $requestHash, $type, $scope, $rows) {
            $existing = McpOperation::query()
                ->where('organization_id', $connection->organization_id)
                ->where('mcp_connection_id', $connection->getKey())
                ->where('idempotency_key', $key)
                ->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new InvalidArgumentException('idempotency_key_payload_mismatch');
                }

                return [$existing, true];
            }

            return [$this->create($type, $scope, $key, $requestHash, $rows), false];
        });

        if (! $replay) {
            if ($operation->total_items <= (int) config('mcp.operations.sync_validation_max_items', 500)) {
                try {
                    $this->validate($operation);
                } catch (Throwable $exception) {
                    $this->failValidation($operation);

                    throw $exception;
                }
            } else {
                ValidateMcpProductOperationJob::dispatch((string) $operation->getKey())
                    ->onQueue(config('mcp.operations.queue'));
            }
        }

        return [...$this->view($operation->refresh()), 'idempotent_replay' => $replay];
    }

    /**
     * Resolves, validates, and previews every row without mutating catalog
     * data. Safe to re-run: each item's preview is recomputed from its input.
     */
    public function validate(McpOperation $operation): void
    {
        if ($operation->status !== McpOperation::STATUS_VALIDATING) return;
        foreach ($this->scopesFor($operation) as $scope) $this->context->authorize($scope);

        $items = $operation->items()->orderBy('ordinal')->get();
        $skuTargets = $this->resolveSkus($items->pluck('locator.sku')->filter(fn ($sku) => is_string($sku))->unique()->values()->all());
        $results = [];
        $candidates = [];

        foreach ($items as $item) {
            if ($item->target_type === 'invalid') continue;
            $targetId = $this->resolveTarget($item, $skuTargets);
            if ($targetId === null) {
                $results[$item->getKey()] = $this->outcome(McpOperationItem::PREVIEW_INVALID, null, [['code' => 'target_not_found']]);
                continue;
            }
            $candidates[$this->family($item->target_type).':'.$targetId][] = [$item, $targetId];
        }

        $evaluations = [];
        foreach ($candidates as $group) {
            $signatures = array_unique(array_map(fn ($entry) => $this->canonical->canonicalJson($entry[0]->input_changes ?? []), $group));
            if (count($group) > 1 && count($signatures) > 1) {
                foreach ($group as [$item, $targetId]) {
                    $results[$item->getKey()] = $this->outcome(McpOperationItem::PREVIEW_CONFLICT, $targetId, [['code' => 'duplicate_target']]);
                }
                continue;
            }
            [$first, $targetId] = array_shift($group);
            foreach ($group as [$duplicate]) {
                $results[$duplicate->getKey()] = $this->outcome(McpOperationItem::PREVIEW_SKIPPED, $targetId, [['code' => 'duplicate_row', 'first_ordinal' => $first->ordinal]]);
            }
            [$results[$first->getKey()], $evaluations[$first->getKey()]] = $this->evaluateItem($first, $targetId);
        }

        $this->flagIdentifierCollisions($items, $results, $evaluations);

        DB::transaction(function () use ($operation, $items, $results, $evaluations) {
            foreach ($items as $item) {
                if (! isset($results[$item->getKey()])) continue;
                $result = $results[$item->getKey()];
                $evaluation = $result['preview_status'] === McpOperationItem::PREVIEW_CHANGED ? ($evaluations[$item->getKey()] ?? null) : null;
                $item->forceFill([
                    'target_id' => $result['target_id'],
                    'preview_status' => $result['preview_status'],
                    'preview_result' => $result['preview_result'],
                    'status' => $evaluation ? McpOperationItem::STATUS_READY : McpOperationItem::STATUS_EXCLUDED,
                    'proposed_values' => $evaluation['proposed'] ?? null,
                    'proposed_hash' => $evaluation['proposed_hash'] ?? null,
                    'expected_version' => $evaluation['version'] ?? null,
                    'expected_state_hash' => $evaluation['state_hash'] ?? null,
                ])->save();
            }

            $counts = $operation->items()->selectRaw('preview_status, count(*) as aggregate')->groupBy('preview_status')->pluck('aggregate', 'preview_status');
            $operation->forceFill([
                'status' => McpOperation::STATUS_PREVIEWED,
                'matched_count' => (int) $operation->items()->whereNotNull('target_id')->where('preview_status', '!=', McpOperationItem::PREVIEW_SKIPPED)->count(),
                'changed_count' => (int) ($counts[McpOperationItem::PREVIEW_CHANGED] ?? 0),
                'unchanged_count' => (int) ($counts[McpOperationItem::PREVIEW_UNCHANGED] ?? 0),
                'skipped_count' => (int) ($counts[McpOperationItem::PREVIEW_SKIPPED] ?? 0),
                'invalid_count' => (int) ($counts[McpOperationItem::PREVIEW_INVALID] ?? 0),
                'conflict_count' => (int) ($counts[McpOperationItem::PREVIEW_CONFLICT] ?? 0),
                'validated_at' => now(),
                'preview_expires_at' => now()->addSeconds((int) config('mcp.operations.preview_ttl_seconds', 900)),
            ]);
            $operation->preview_hash = $this->previewHash($operation);
            $operation->save();
        });

        $this->auditOperation('mcp.product_operation_prepared', $operation);
    }

    /** @return array<string, mixed> */
    public function execute(string $operationId, string $fingerprint): array
    {
        if (! preg_match('/\A[0-9a-f]{64}\z/', $fingerprint)) throw new InvalidArgumentException('invalid_preview_fingerprint');
        $operation = $this->operation($operationId);
        foreach ($this->scopesFor($operation) as $scope) $this->context->authorize($scope);
        $fingerprintHash = hash('sha256', $fingerprint);

        [$outcome, $operation] = DB::transaction(function () use ($operationId, $fingerprint, $fingerprintHash) {
            $operation = McpOperation::query()->whereKey($operationId)->lockForUpdate()->firstOrFail();
            if ($operation->executed_fingerprint_hash !== null && hash_equals($operation->executed_fingerprint_hash, $fingerprintHash)) {
                return ['replay', $operation];
            }
            if ($operation->status === McpOperation::STATUS_VALIDATING) return ['not_ready', $operation];
            if ($operation->status !== McpOperation::STATUS_PREVIEWED) return ['stale', $operation];
            if ($operation->preview_expires_at === null || $operation->preview_expires_at->isPast()) {
                $this->markExpired($operation, 'preview_expired');

                return ['stale', $operation];
            }
            if ($operation->changed_count === 0) return ['nothing', $operation];
            if (! hash_equals($this->fingerprint($operation), $fingerprint)
                || ! hash_equals((string) $operation->preview_hash, $this->previewHash($operation))) {
                return ['stale', $operation];
            }
            if ($this->staleTargetCount($operation) > 0) {
                $this->markExpired($operation, 'preview_stale');

                return ['stale', $operation];
            }
            $active = McpOperation::query()
                ->where('mcp_connection_id', $operation->mcp_connection_id)
                ->whereIn('status', [McpOperation::STATUS_QUEUED, McpOperation::STATUS_RUNNING])
                ->count();
            if ($active >= (int) config('mcp.operations.max_active_per_connection', 3)) return ['busy', $operation];

            $operation->items()->where('status', McpOperationItem::STATUS_READY)->update(['status' => McpOperationItem::STATUS_PENDING, 'updated_at' => now()]);
            $operation->forceFill([
                'status' => McpOperation::STATUS_QUEUED,
                'executed_fingerprint_hash' => $fingerprintHash,
                'execute_requested_at' => now(),
            ])->save();

            $operation->items()->where('status', McpOperationItem::STATUS_PENDING)->select(['id'])
                ->chunkById(max(1, (int) config('mcp.operations.chunk_size', 100)), function ($chunk) use ($operation) {
                    ProcessMcpProductOperationChunkJob::dispatch((string) $operation->getKey(), (int) $chunk->first()->id, (int) $chunk->last()->id)
                        ->onQueue(config('mcp.operations.queue'))
                        ->afterCommit();
                }, 'id');

            return ['queued', $operation];
        });

        return match ($outcome) {
            'replay' => [...$this->view($operation), 'idempotent_replay' => true],
            'queued' => (function () use ($operation) {
                $this->auditOperation('mcp.product_operation_queued', $operation);

                return [...$this->view($operation), 'idempotent_replay' => false];
            })(),
            'not_ready' => throw new InvalidArgumentException('operation_not_ready'),
            'nothing' => throw new InvalidArgumentException('nothing_to_execute'),
            'busy' => throw new InvalidArgumentException('too_many_active_operations'),
            default => throw new InvalidArgumentException('stale_or_invalid_preview'),
        };
    }

    /** @return array<string, mixed> */
    public function show(string $operationId): array
    {
        $operation = $this->operation($operationId);
        foreach ($this->scopesFor($operation) as $scope) $this->context->authorize($scope);

        return $this->view($operation);
    }

    /** @return array<string, mixed> */
    public function items(string $operationId, int $page, int $perPage, ?string $status, ?string $previewStatus): array
    {
        $operation = $this->operation($operationId);
        foreach ($this->scopesFor($operation) as $scope) $this->context->authorize($scope);
        if ($operation->details_purged_at !== null) throw new InvalidArgumentException('operation_details_expired');
        $perPage = max(1, min($perPage, (int) config('mcp.operations.items_max_per_page', 200)));
        $page = max(1, $page);

        $query = $operation->items()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($previewStatus !== null, fn ($query) => $query->where('preview_status', $previewStatus));
        $total = (clone $query)->count();
        $rows = $query->orderBy('ordinal')->forPage($page, $perPage)->get();

        return [
            'operation_id' => (string) $operation->getKey(),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'has_more' => $page * $perPage < $total,
            'items' => $rows->map(fn (McpOperationItem $item) => $this->itemView($item))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function view(McpOperation $operation): array
    {
        if ($operation->status === McpOperation::STATUS_PREVIEWED && ($operation->preview_expires_at === null || $operation->preview_expires_at->isPast())) {
            McpOperation::query()->whereKey($operation->getKey())->where('status', McpOperation::STATUS_PREVIEWED)
                ->update(['status' => McpOperation::STATUS_EXPIRED, 'failure_code' => 'preview_expired', 'failure_message' => self::message('preview_expired'), 'updated_at' => now()]);
            $operation->refresh();
        }

        $executable = $operation->status === McpOperation::STATUS_PREVIEWED && $operation->changed_count > 0;
        $live = in_array($operation->status, [McpOperation::STATUS_QUEUED, McpOperation::STATUS_RUNNING], true);
        $executionCounts = $live
            ? $operation->items()->whereIn('status', [McpOperationItem::STATUS_SUCCEEDED, McpOperationItem::STATUS_FAILED])
                ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')
            : collect([McpOperationItem::STATUS_SUCCEEDED => $operation->succeeded_count, McpOperationItem::STATUS_FAILED => $operation->failed_count]);
        $succeeded = (int) ($executionCounts[McpOperationItem::STATUS_SUCCEEDED] ?? 0);
        $failed = (int) ($executionCounts[McpOperationItem::STATUS_FAILED] ?? 0);
        $toProcess = $operation->execute_requested_at !== null ? $operation->changed_count : 0;

        return [
            'operation_id' => (string) $operation->getKey(),
            'type' => $operation->type,
            'status' => $operation->status,
            'counts' => [
                'total' => $operation->total_items,
                'matched' => $operation->matched_count,
                'changed' => $operation->changed_count,
                'unchanged' => $operation->unchanged_count,
                'skipped' => $operation->skipped_count,
                'invalid' => $operation->invalid_count,
                'conflicts' => $operation->conflict_count,
            ],
            'progress' => [
                'to_process' => $toProcess,
                'processed' => $succeeded + $failed,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'percent' => $toProcess > 0 ? round(($succeeded + $failed) * 100 / $toProcess, 1) : ($operation->execute_requested_at ? 100.0 : 0.0),
            ],
            'preview' => [
                'executable' => $executable,
                'preview_fingerprint' => $executable ? $this->fingerprint($operation) : null,
                'expires_at' => $executable ? $operation->preview_expires_at?->toIso8601String() : null,
            ],
            'failure' => $operation->failure_code === null ? null : ['code' => $operation->failure_code, 'message' => $operation->failure_message],
            'timestamps' => [
                'created_at' => $operation->created_at?->toIso8601String(),
                'validated_at' => $operation->validated_at?->toIso8601String(),
                'execute_requested_at' => $operation->execute_requested_at?->toIso8601String(),
                'started_at' => $operation->started_at?->toIso8601String(),
                'completed_at' => $operation->completed_at?->toIso8601String(),
            ],
            'details_available' => $operation->details_purged_at === null,
            'summary' => $this->summary($operation, $succeeded, $failed),
        ];
    }

    /** Current expected fingerprint; deterministic so a polling client can retrieve it. */
    public function fingerprint(McpOperation $operation): string
    {
        return hash_hmac('sha256', implode('|', [
            'mcp-product-operation',
            (string) $operation->getKey(),
            (string) $operation->preview_hash,
            (string) $operation->preview_expires_at?->getTimestamp(),
        ]), (string) config('app.key'));
    }

    /** @return list<string> */
    public function scopesFor(McpOperation $operation): array
    {
        return array_values(array_unique(['products.view', ...($operation->required_scopes ?? [])]));
    }

    /** @param list<mixed> $rows */
    private function create(string $type, string $scope, string $key, string $requestHash, array $rows): McpOperation
    {
        $connection = $this->context->connectionOrFail();
        $user = $this->context->user();

        return DB::transaction(function () use ($type, $scope, $key, $requestHash, $rows, $connection, $user) {
            $operation = new McpOperation;
            $operation->forceFill([
                'organization_id' => $connection->organization_id,
                'mcp_connection_id' => $connection->getKey(),
                'mcp_connection_name' => $connection->name,
                'user_id' => $user->getKey(),
                'initiated_by' => ['user_id' => $user->getKey(), 'name' => $user->name, 'connection_id' => (string) $connection->getKey(), 'connection_name' => $connection->name],
                'type' => $type,
                'status' => McpOperation::STATUS_VALIDATING,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'required_scopes' => [$scope],
                'total_items' => count($rows),
            ])->save();

            $now = now();
            foreach (array_chunk($rows, 500, true) as $chunk) {
                $records = [];
                foreach ($chunk as $index => $row) {
                    $normalized = $this->normalizeRow($type, $row);
                    $invalid = $normalized['issue'] !== null;
                    $records[] = [
                        'mcp_operation_id' => $operation->getKey(),
                        'organization_id' => $connection->organization_id,
                        'ordinal' => $index + 1,
                        'target_type' => $invalid ? 'invalid' : $normalized['target_type'],
                        'target_id' => null,
                        'locator' => json_encode($normalized['locator'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'input_changes' => $invalid ? null : json_encode((object) $normalized['changes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'preview_status' => $invalid ? McpOperationItem::PREVIEW_INVALID : 'pending',
                        'preview_result' => $invalid ? json_encode(['issues' => [$normalized['issue']]], JSON_THROW_ON_ERROR) : null,
                        'status' => $invalid ? McpOperationItem::STATUS_EXCLUDED : McpOperationItem::STATUS_VALIDATING,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                McpOperationItem::query()->insert($records);
            }

            return $operation;
        });
    }

    /**
     * Shape-checks one client row against the operation's explicit allow-list.
     *
     * @return array{target_type: string, locator: array<string, mixed>, changes: array<string, mixed>, issue: array<string, mixed>|null}
     */
    private function normalizeRow(string $type, mixed $row): array
    {
        $invalid = fn (string $code, array $extra = []) => ['target_type' => 'invalid', 'locator' => $this->locatorOf($row), 'changes' => [], 'issue' => ['code' => $code, ...$extra]];
        if (! is_array($row) || ($row !== [] && array_is_list($row))) return $invalid('invalid_row');
        $unknown = array_values(array_diff(array_keys($row), self::ROW_KEYS[$type]));
        if ($unknown !== []) return $invalid('invalid_row', ['fields' => array_slice($unknown, 0, 20)]);

        $idKey = match ($type) {
            McpOperation::TYPE_PRODUCT_UPDATE => 'target_id',
            McpOperation::TYPE_PURCHASE_PRICE_UPDATE => 'variant_id',
            default => 'product_id',
        };
        $hasId = array_key_exists($idKey, $row) && $row[$idKey] !== null;
        $hasSku = array_key_exists('sku', $row) && $row['sku'] !== null;
        if ($hasId === $hasSku) return $invalid('invalid_row', ['message' => "Provide exactly one of {$idKey} or sku."]);
        if ($hasId && (! is_int($row[$idKey]) || $row[$idKey] < 1)) return $invalid('invalid_row', ['fields' => [$idKey]]);
        if ($hasSku && (! is_string($row['sku']) || trim($row['sku']) === '' || mb_strlen($row['sku']) > 255)) return $invalid('invalid_row', ['fields' => ['sku']]);
        $locator = $hasId ? ['id' => $row[$idKey]] : ['sku' => trim($row['sku'])];

        if ($type === McpOperation::TYPE_PRODUCT_ARCHIVE) {
            return ['target_type' => 'archive_product', 'locator' => $locator, 'changes' => [], 'issue' => null];
        }

        if ($type === McpOperation::TYPE_PURCHASE_PRICE_UPDATE) {
            $price = $row['purchase_price'] ?? null;
            if (! is_string($price) || ! preg_match(self::DECIMAL_PATTERN, $price)) return $invalid('invalid_decimal', ['fields' => ['purchase_price']]);

            return ['target_type' => 'purchase_price', 'locator' => $locator, 'changes' => ['purchase_price' => $price], 'issue' => null];
        }

        $targetType = $row['target_type'] ?? null;
        if (! in_array($targetType, ['product', 'variant'], true)) return $invalid('invalid_row', ['fields' => ['target_type']]);
        $changes = $row['changes'] ?? null;
        if (! is_array($changes) || $changes === [] || array_is_list($changes)) return $invalid('invalid_row', ['fields' => ['changes']]);
        if (array_key_exists('purchase_price', $changes)) {
            return $invalid('forbidden_fields', ['fields' => ['purchase_price'], 'message' => 'Use prepare_purchase_price_bulk_update for purchase costs.']);
        }
        if ($targetType === 'variant') {
            $badDecimals = array_values(array_filter(self::DECIMAL_FIELDS, fn ($field) => array_key_exists($field, $changes)
                && $changes[$field] !== null && (! is_string($changes[$field]) || ! preg_match(self::DECIMAL_PATTERN, $changes[$field]))));
            if ($badDecimals !== []) return $invalid('invalid_decimal', ['fields' => $badDecimals]);
        }

        return ['target_type' => $targetType, 'locator' => $locator, 'changes' => $changes, 'issue' => null];
    }

    /** @return array<string, mixed> */
    private function locatorOf(mixed $row): array
    {
        if (! is_array($row)) return [];
        $locator = [];
        foreach (['target_id', 'variant_id', 'product_id'] as $key) {
            if (is_int($row[$key] ?? null)) $locator['id'] = $row[$key];
        }
        if (is_string($row['sku'] ?? null)) $locator['sku'] = mb_substr(trim($row['sku']), 0, 255);

        return $locator;
    }

    /** @param list<string> $skus @return array<string, array{variant_id:int, product_id:int}> */
    private function resolveSkus(array $skus): array
    {
        $map = [];
        foreach (array_chunk($skus, 1000) as $chunk) {
            ProductVariant::query()->where('organization_id', $this->organizationId())->whereIn('sku', $chunk)
                ->get(['id', 'product_id', 'sku'])
                ->each(function (ProductVariant $variant) use (&$map) {
                    $map[mb_strtolower(trim((string) $variant->sku))] = ['variant_id' => (int) $variant->getKey(), 'product_id' => (int) $variant->product_id];
                });
        }

        return $map;
    }

    /** @param array<string, array{variant_id:int, product_id:int}> $skuTargets */
    private function resolveTarget(McpOperationItem $item, array $skuTargets): ?int
    {
        $locator = $item->locator ?? [];
        if (isset($locator['id'])) return (int) $locator['id'];
        $match = $skuTargets[mb_strtolower(trim((string) ($locator['sku'] ?? '')))] ?? null;
        if ($match === null) return null;

        return $this->family($item->target_type) === 'product' ? $match['product_id'] : $match['variant_id'];
    }

    private function family(string $targetType): string
    {
        return in_array($targetType, ['product', 'archive_product'], true) ? 'product' : 'variant';
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>|null} */
    private function evaluateItem(McpOperationItem $item, int $targetId): array
    {
        try {
            $evaluation = $this->changes->evaluate($item->target_type, $targetId, $item->input_changes ?? []);
        } catch (ModelNotFoundException) {
            return [$this->outcome(McpOperationItem::PREVIEW_INVALID, null, [['code' => 'target_not_found']]), null];
        } catch (ValidationException $exception) {
            return [$this->outcome(McpOperationItem::PREVIEW_INVALID, $targetId, [['code' => 'validation_failed', 'fields' => $this->sanitizedErrors($exception)]]), null];
        }

        $before = array_intersect_key($evaluation['current'], array_flip($evaluation['changed']));
        $extra = ['changed_fields' => $evaluation['changed'], 'before' => $before];
        if ($evaluation['forbidden'] !== []) {
            return [$this->outcome(McpOperationItem::PREVIEW_CONFLICT, $targetId, [['code' => 'forbidden_fields', 'fields' => $evaluation['forbidden']]], $extra), null];
        }
        if ($evaluation['woo'] !== []) {
            return [$this->outcome(McpOperationItem::PREVIEW_CONFLICT, $targetId, [['code' => 'woocommerce_managed_fields', 'fields' => $evaluation['woo']]], $extra), null];
        }
        if ($evaluation['changed'] === []) {
            return [$this->outcome(McpOperationItem::PREVIEW_UNCHANGED, $targetId, [], $extra), null];
        }

        unset($evaluation['model']);

        return [$this->outcome(McpOperationItem::PREVIEW_CHANGED, $targetId, [], $extra), $evaluation];
    }

    /**
     * The shared validator checks identifiers against the database only, so two
     * rows assigning the same new SKU/reference/barcode would both pass.
     *
     * @param iterable<McpOperationItem> $items
     * @param array<int, array<string, mixed>> $results
     * @param array<int, array<string, mixed>|null> $evaluations
     */
    private function flagIdentifierCollisions(iterable $items, array &$results, array $evaluations): void
    {
        foreach (self::IDENTIFIER_FIELDS as $field) {
            $claims = [];
            foreach ($items as $item) {
                $evaluation = $evaluations[$item->getKey()] ?? null;
                if (($results[$item->getKey()]['preview_status'] ?? null) !== McpOperationItem::PREVIEW_CHANGED
                    || $evaluation === null || ! in_array($field, $evaluation['changed'], true)) continue;
                $value = $evaluation['proposed'][$field] ?? null;
                if ($value === null || $value === '') continue;
                $claims[mb_strtolower(trim((string) $value))][] = $item->getKey();
            }
            foreach ($claims as $itemIds) {
                if (count($itemIds) < 2) continue;
                foreach ($itemIds as $itemId) {
                    $results[$itemId]['preview_status'] = McpOperationItem::PREVIEW_CONFLICT;
                    $results[$itemId]['preview_result']['issues'][] = ['code' => 'duplicate_identifier_in_request', 'fields' => [$field]];
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $issues @param array<string, mixed> $extra @return array<string, mixed> */
    private function outcome(string $previewStatus, ?int $targetId, array $issues, array $extra = []): array
    {
        return [
            'preview_status' => $previewStatus,
            'target_id' => $targetId,
            'preview_result' => [...$extra, 'issues' => array_map(fn ($issue) => [...$issue, 'message' => $issue['message'] ?? self::message($issue['code'])], $issues)],
        ];
    }

    /** @return array<string, list<string>> */
    private function sanitizedErrors(ValidationException $exception): array
    {
        return collect($exception->errors())->take(20)
            ->map(fn ($messages) => array_values(array_map(fn ($message) => mb_substr((string) $message, 0, 200), array_slice((array) $messages, 0, 3))))
            ->all();
    }

    private function previewHash(McpOperation $operation): string
    {
        $items = [];
        $operation->items()->where('status', McpOperationItem::STATUS_READY)->orderBy('ordinal')
            ->select(['id', 'ordinal', 'target_type', 'target_id', 'proposed_hash', 'expected_version', 'expected_state_hash'])
            ->chunk(1000, function ($chunk) use (&$items) {
                foreach ($chunk as $item) {
                    $items[] = [$item->ordinal, $item->target_type, (int) $item->target_id, $item->proposed_hash, $item->expected_version, $item->expected_state_hash];
                }
            });
        $scopes = $operation->required_scopes ?? [];
        sort($scopes);

        return hash('sha256', $this->canonical->canonicalJson([
            'organization_id' => (int) $operation->organization_id,
            'connection_id' => (string) $operation->mcp_connection_id,
            'operation_id' => (string) $operation->getKey(),
            'type' => $operation->type,
            'required_scopes' => $scopes,
            'items' => $items,
        ]));
    }

    private function staleTargetCount(McpOperation $operation): int
    {
        $stale = 0;
        $operation->items()->where('status', McpOperationItem::STATUS_READY)
            ->select(['id', 'target_type', 'target_id', 'expected_version'])
            ->chunkById(1000, function ($chunk) use (&$stale, $operation) {
                foreach ($chunk->groupBy(fn ($item) => $this->family($item->target_type)) as $family => $items) {
                    $model = $family === 'product' ? Product::query() : ProductVariant::query();
                    $versions = $model->where('organization_id', $operation->organization_id)
                        ->whereIn('id', $items->pluck('target_id')->all())
                        ->get(['id', 'updated_at'])
                        ->mapWithKeys(fn ($row) => [(int) $row->getKey() => $row->updated_at?->format('Y-m-d\TH:i:s.uP') ?? 'missing']);
                    foreach ($items as $item) {
                        if (($versions[(int) $item->target_id] ?? null) !== $item->expected_version) $stale++;
                    }
                }
            });

        return $stale;
    }

    public function failValidation(McpOperation $operation): void
    {
        McpOperation::query()->whereKey($operation->getKey())->where('status', McpOperation::STATUS_VALIDATING)->update([
            'status' => McpOperation::STATUS_FAILED,
            'failure_code' => 'validation_internal_error',
            'failure_message' => self::message('validation_internal_error'),
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function markExpired(McpOperation $operation, string $code): void
    {
        $operation->forceFill(['status' => McpOperation::STATUS_EXPIRED, 'failure_code' => $code, 'failure_message' => self::message($code)])->save();
    }

    private function operation(string $operationId): McpOperation
    {
        $connection = $this->context->connectionOrFail();
        if (! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $operationId)) throw (new ModelNotFoundException)->setModel(McpOperation::class);

        return McpOperation::query()
            ->where('organization_id', $connection->organization_id)
            ->where('mcp_connection_id', $connection->getKey())
            ->whereKey(strtolower($operationId))
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function itemView(McpOperationItem $item): array
    {
        $preview = $item->preview_result ?? [];
        $changed = $preview['changed_fields'] ?? [];

        return [
            'ordinal' => $item->ordinal,
            'target' => ['type' => $item->target_type, 'id' => $item->target_id],
            'locator' => $item->locator,
            'preview_status' => $item->preview_status,
            'status' => $item->status,
            'changed_fields' => $changed,
            'before' => $preview['before'] ?? [],
            'after' => array_intersect_key($item->proposed_values ?? [], array_flip($changed)),
            'issues' => $preview['issues'] ?? [],
            'error' => $item->error_code === null ? null : ['code' => $item->error_code, 'message' => $item->error_message],
            'attempts' => $item->attempts,
            'processed_at' => $item->processed_at?->toIso8601String(),
        ];
    }

    private function summary(McpOperation $operation, int $succeeded, int $failed): string
    {
        $parts = ["{$operation->total_items} rows", "{$operation->changed_count} changed", "{$operation->unchanged_count} unchanged"];
        if ($operation->skipped_count) $parts[] = "{$operation->skipped_count} skipped";
        if ($operation->invalid_count) $parts[] = "{$operation->invalid_count} invalid";
        if ($operation->conflict_count) $parts[] = "{$operation->conflict_count} conflicts";
        if ($operation->execute_requested_at !== null) $parts[] = "{$succeeded} succeeded, {$failed} failed";

        return implode(', ', $parts).'.';
    }

    public function auditOperation(string $event, McpOperation $operation): void
    {
        $this->audit->record(
            $event,
            $this->context->connection()?->user,
            $this->context->connection()?->organization ?? $operation->organization,
            newValues: [
                'operation_id' => (string) $operation->getKey(),
                'type' => $operation->type,
                'status' => $operation->status,
                'total' => $operation->total_items,
                'changed' => $operation->changed_count,
                'unchanged' => $operation->unchanged_count,
                'skipped' => $operation->skipped_count,
                'invalid' => $operation->invalid_count,
                'conflicts' => $operation->conflict_count,
                'succeeded' => $operation->succeeded_count,
                'failed' => $operation->failed_count,
            ],
        );
    }

    private function organizationId(): int
    {
        return (int) $this->context->organization()->getKey();
    }
}
