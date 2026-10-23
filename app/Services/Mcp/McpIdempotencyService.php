<?php

namespace App\Services\Mcp;

use App\Models\McpIdempotencyRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class McpIdempotencyService
{
    public function __construct(private readonly McpExecutionContext $context) {}

    /**
     * @param array<string, mixed> $request
     * @param callable(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    public function execute(string $tool, string $key, array $request, callable $operation): array
    {
        $connection = $this->context->connectionOrFail();
        $key = trim($key);
        if ($key === '' || mb_strlen($key) > 128) {
            throw new InvalidArgumentException('invalid_idempotency_key');
        }
        $requestHash = hash('sha256', $this->canonicalJson($request));

        $lock = Cache::lock('mcp:idempotency:'.hash('sha256', $connection->getKey().'|'.$tool.'|'.$key), 30);

        return $lock->block(5, fn () => DB::transaction(function () use ($tool, $key, $requestHash, $operation, $connection) {
            $existing = McpIdempotencyRecord::query()
                ->where('organization_id', $connection->organization_id)
                ->where('mcp_connection_id', $connection->getKey())
                ->where('tool', $tool)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new InvalidArgumentException('idempotency_key_payload_mismatch');
                }

                return [...$existing->response, 'idempotent_replay' => true];
            }

            $response = $operation();
            $record = new McpIdempotencyRecord;
            $record->id = (string) Str::ulid();
            $record->organization_id = $connection->organization_id;
            $record->mcp_connection_id = $connection->getKey();
            $record->tool = $tool;
            $record->idempotency_key = $key;
            $record->request_hash = $requestHash;
            $record->target_type = isset($response['target']['type']) ? (string) $response['target']['type'] : null;
            $record->target_id = isset($response['target']['id']) ? (string) $response['target']['id'] : null;
            $record->response = $response;
            $record->save();

            return [...$response, 'idempotent_replay' => false];
        }));
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
