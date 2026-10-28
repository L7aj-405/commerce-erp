<?php

namespace App\Services\Mcp;

use App\Models\McpIdempotencyRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class McpIdempotencyService
{
    /** Column sizes of mcp_idempotency_records.target_type / target_id. */
    public const TARGET_TYPE_MAX = 32;

    public const TARGET_ID_MAX = 64;

    public function __construct(private readonly McpExecutionContext $context) {}

    /**
     * @param array<string, mixed> $request
     * @param callable(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    public function execute(string $tool, string $key, array $request, callable $operation): array
    {
        return $this->run($tool, $key, $request, $operation, transactional: true);
    }

    /**
     * For operations with an external side effect (e.g. sending an email) whose
     * own ERP records must survive a failure: the operation is NOT wrapped in a
     * database transaction, and whatever it returns — success or failure — is
     * stored, so a retry with the same key replays instead of repeating it.
     * Serialised per key by the cache lock.
     *
     * @param array<string, mixed> $request
     * @param callable(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    public function executeExternal(string $tool, string $key, array $request, callable $operation): array
    {
        return $this->run($tool, $key, $request, $operation, transactional: false);
    }

    /**
     * @param array<string, mixed> $request
     * @param callable(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function run(string $tool, string $key, array $request, callable $operation, bool $transactional): array
    {
        $connection = $this->context->connectionOrFail();
        $key = trim($key);
        if ($key === '' || mb_strlen($key) > 128) {
            throw new InvalidArgumentException('invalid_idempotency_key');
        }
        $requestHash = hash('sha256', $this->canonicalJson($request));

        $lock = Cache::lock('mcp:idempotency:'.hash('sha256', $connection->getKey().'|'.$tool.'|'.$key), $transactional ? 30 : 120);
        $body = function () use ($tool, $key, $requestHash, $operation, $connection, $transactional) {
            $existing = McpIdempotencyRecord::query()
                ->where('organization_id', $connection->organization_id)
                ->where('mcp_connection_id', $connection->getKey())
                ->where('tool', $tool)
                ->where('idempotency_key', $key)
                ->when($transactional, fn ($query) => $query->lockForUpdate())
                ->first();

            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new InvalidArgumentException('idempotency_key_payload_mismatch');
                }

                return [...$existing->response, 'idempotent_replay' => true];
            }

            $response = $operation();
            $targetType = isset($response['target']['type']) ? (string) $response['target']['type'] : null;
            $targetId = isset($response['target']['id']) ? (string) $response['target']['id'] : null;
            // Fail loudly on every database: MySQL rejects an oversized value, SQLite would silently keep it.
            if (($targetType !== null && mb_strlen($targetType) > self::TARGET_TYPE_MAX) || ($targetId !== null && mb_strlen($targetId) > self::TARGET_ID_MAX)) {
                throw new LogicException("MCP idempotency target for {$tool} exceeds the stored column size.");
            }
            $record = new McpIdempotencyRecord;
            $record->id = (string) Str::ulid();
            $record->organization_id = $connection->organization_id;
            $record->mcp_connection_id = $connection->getKey();
            $record->tool = $tool;
            $record->idempotency_key = $key;
            $record->request_hash = $requestHash;
            $record->target_type = $targetType;
            $record->target_id = $targetId;
            $record->response = $response;
            $record->save();

            return [...$response, 'idempotent_replay' => false];
        };

        return $lock->block(5, fn () => $transactional ? DB::transaction($body) : $body());
    }

    /** @param array<string, mixed> $value */
    public function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }
            ksort($item);

            return array_map($normalize, $item);
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
