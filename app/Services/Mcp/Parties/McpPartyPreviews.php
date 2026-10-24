<?php

namespace App\Services\Mcp\Parties;

use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Short-lived, single-use preview fingerprints for customer and contact
 * changes. A fingerprint is bound to the organization, connection and user
 * plus the caller's binding (entity, mode, target, payload/state/proposal
 * hashes, required scope); execution must present the exact same binding.
 */
final class McpPartyPreviews
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly McpIdempotencyService $canonical,
    ) {}

    /**
     * @param array<string, mixed> $binding
     * @return array{0: string, 1: string}
     */
    public function issue(string $entity, array $binding): array
    {
        $fingerprint = bin2hex(random_bytes(32));
        $expires = now()->addSeconds((int) config('mcp.preview_ttl_seconds', 300));
        Cache::put($this->key($entity, 'preview', $fingerprint), $this->bound($binding), $expires);

        return [$fingerprint, $expires->toIso8601String()];
    }

    /**
     * Atomically consume a matching fingerprint. Throws when it is missing,
     * expired, already consumed, or bound to anything else.
     *
     * @param array<string, mixed> $binding
     */
    public function consume(string $entity, string $fingerprint, array $binding): void
    {
        $stored = preg_match('/^[0-9a-f]{64}$/', $fingerprint) ? Cache::get($this->key($entity, 'preview', $fingerprint)) : null;
        if (! is_array($stored) || ! hash_equals($this->hash($stored), $this->hash($this->bound($binding)))
            || ! Cache::add($this->key($entity, 'consumed', $fingerprint), true, now()->addSeconds((int) config('mcp.preview_ttl_seconds', 300) * 2))) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }
        Cache::forget($this->key($entity, 'preview', $fingerprint));
    }

    /** Give a consumed fingerprint back when the ERP action failed, so the same preview can be retried. */
    public function release(string $entity, string $fingerprint, array $binding): void
    {
        Cache::forget($this->key($entity, 'consumed', $fingerprint));
        Cache::put($this->key($entity, 'preview', $fingerprint), $this->bound($binding), now()->addSeconds((int) config('mcp.preview_ttl_seconds', 300)));
    }

    /** Remember a completed one-shot transition so a retry with the same fingerprint can be answered safely. */
    public function markDone(string $entity, string $fingerprint, int $targetId): void
    {
        Cache::put($this->key($entity, 'done', $fingerprint), $this->bound(['target_id' => $targetId]), now()->addSeconds((int) config('mcp.preview_ttl_seconds', 300) * 2));
    }

    public function wasDone(string $entity, string $fingerprint, int $targetId): bool
    {
        $done = preg_match('/^[0-9a-f]{64}$/', $fingerprint) ? Cache::get($this->key($entity, 'done', $fingerprint)) : null;

        return is_array($done) && hash_equals($this->hash($done), $this->hash($this->bound(['target_id' => $targetId])));
    }

    /** @param array<string, mixed> $value */
    public function hash(array $value): string
    {
        return hash('sha256', $this->canonical->canonicalJson($value));
    }

    /**
     * @param array<string, mixed> $binding
     * @return array<string, mixed>
     */
    private function bound(array $binding): array
    {
        $connection = $this->context->connectionOrFail();

        return [
            ...$binding,
            'organization_id' => (int) $connection->organization_id,
            'connection_id' => (string) $connection->getKey(),
            'user_id' => (int) $connection->user_id,
        ];
    }

    private function key(string $entity, string $kind, string $fingerprint): string
    {
        return "mcp:{$entity}-{$kind}:".hash('sha256', $fingerprint);
    }
}
