<?php

namespace App\Services\Mcp\Inventory;

use App\Actions\Inventory\ManualStockAdjustmentAction;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryBalanceLocker;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Parties\McpPartyPreviews;
use App\Support\InventoryQuantity;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Throwable;

/**
 * Manual stock adjustment (Set / Add / Remove) over MCP.
 *
 * The same ManualStockAdjustmentAction as the web dialog does the work: it
 * delegates to AdjustInventoryAction, so the balance is locked, reservations and
 * the no-negative-stock rule are enforced, exactly one immutable
 * InventoryMovement is written and `inventory.adjusted` is audited.
 *
 * Environment: MCP routes never run the Test Mode (sandbox) resolver, so this
 * service always reads and writes PRODUCTION inventory, whatever the browser
 * session of the same user is doing.
 */
final class McpStockAdjustmentService
{
    public const ENTITY = 'stock_adjustment';

    /** Idempotency target type (varchar(32)); the id is the created movement. */
    public const TARGET_TYPE = 'inventory_movement';

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly ManualStockAdjustmentAction $adjustment,
        private readonly InventoryBalanceLocker $balances,
        private readonly McpPartyPreviews $previews,
    ) {}

    /**
     * Canonical candidate (the idempotency and fingerprint payload).
     *
     * @return array{variant_id: int, warehouse_id: int, operation: string, quantity: string, reason: ?string, reference: ?string}
     */
    public function normalize(int $variantId, int $warehouseId, string $operation, int|float|string $quantity, ?string $reason, ?string $reference): array
    {
        if ($variantId < 1 || $warehouseId < 1 || ! in_array($operation, ManualStockAdjustmentAction::OPERATIONS, true)) {
            throw new InvalidArgumentException('invalid_arguments');
        }
        $clean = fn (?string $value) => $value === null || trim($value) === '' ? null : trim($value);

        return [
            'variant_id' => $variantId,
            'warehouse_id' => $warehouseId,
            'operation' => $operation,
            'quantity' => InventoryQuantity::normalize($quantity),
            'reason' => $clean($reason),
            'reference' => $clean($reference),
        ];
    }

    /**
     * No mutation, no lock.
     *
     * @param array{variant_id: int, warehouse_id: int, operation: string, quantity: string, reason: ?string, reference: ?string} $payload
     * @return array<string, mixed>
     */
    public function preview(array $payload): array
    {
        [$warehouse, $variant] = $this->locate($payload);
        $plan = $this->adjustment->preview($this->actor(), $this->context->organization(), $warehouse, $variant, $payload['operation'], $payload['quantity'], $payload['reason'] ?? '');

        $fingerprint = $expiresAt = null;
        if ($plan['valid']) {
            [$fingerprint, $expiresAt] = $this->previews->issue(self::ENTITY, $this->binding($payload, $plan['on_hand_before'], $plan['reserved']));
        }

        return [
            ...$this->describe($warehouse, $variant),
            'operation' => $plan['operation'],
            'quantity' => $payload['quantity'],
            'on_hand_before' => $plan['on_hand_before'],
            'reserved' => $plan['reserved'],
            'available_before' => $plan['available_before'],
            'delta' => $plan['delta'],
            'on_hand_after' => $plan['on_hand_after'],
            'available_after' => $plan['available_after'],
            'valid' => $plan['valid'],
            'issues' => array_map(fn (array $issue) => ['path' => $issue['field'], 'code' => $issue['code'], 'message' => $issue['message']], $plan['issues']),
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'environment' => 'production',
            'note' => 'Dry run: nothing was changed. apply_stock_adjustment creates exactly one adjustment movement.',
        ];
    }

    /**
     * Must run inside the caller's transaction (idempotency wrapper).
     *
     * @param array{variant_id: int, warehouse_id: int, operation: string, quantity: string, reason: ?string, reference: ?string} $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload, string $fingerprint): array
    {
        [$warehouse, $variant] = $this->locate($payload);
        $organization = $this->context->organization();
        if ($payload['reason'] === null) {
            throw new InvalidArgumentException('reason_required');
        }

        $balance = $this->balances->lock($organization, $warehouse, $variant);
        $this->previews->consume(self::ENTITY, $fingerprint, $this->binding($payload, $balance->on_hand, $balance->reserved));
        $before = ['on_hand' => $balance->on_hand, 'reserved' => $balance->reserved];

        try {
            $movement = $this->adjustment->execute($this->actor(), $organization, $warehouse, $variant, $payload['operation'], $payload['quantity'], $payload['reason'], $payload['reference'], $balance->on_hand);
        } catch (Throwable $exception) {
            $this->previews->release(self::ENTITY, $fingerprint, $this->binding($payload, $before['on_hand'], $before['reserved']));
            throw $exception;
        }

        return [
            'target' => ['type' => self::TARGET_TYPE, 'id' => (string) $movement->getKey()],
            ...$this->describe($warehouse, $variant),
            'operation' => $payload['operation'],
            'on_hand_before' => $movement->quantity_before,
            'on_hand_after' => $movement->quantity_after,
            'reserved' => $before['reserved'],
            'available_after' => InventoryQuantity::subtract($movement->quantity_after, $before['reserved']),
            'delta' => $movement->quantity,
            'movement' => $this->movement($movement),
            'environment' => 'production',
        ];
    }

    /** @return array<string, mixed> */
    private function movement(InventoryMovement $movement): array
    {
        return [
            'id' => (int) $movement->getKey(),
            'type' => $movement->movement_type->value,
            'quantity' => $movement->quantity,
            'quantity_before' => $movement->quantity_before,
            'quantity_after' => $movement->quantity_after,
            'reason' => $movement->reason,
            'reference' => $movement->reference,
        ];
    }

    /** @return array<string, mixed> */
    private function describe(Warehouse $warehouse, ProductVariant $variant): array
    {
        $variant->loadMissing('product:id,name');

        return [
            'product' => ['id' => (int) $variant->product_id, 'name' => $variant->product?->name],
            'variant' => ['id' => (int) $variant->getKey(), 'sku' => $variant->sku, 'label' => $variant->label],
            'warehouse' => ['id' => (int) $warehouse->getKey(), 'name' => $warehouse->name, 'code' => $warehouse->code],
        ];
    }

    /**
     * Organization-scoped lookup: a foreign or unknown id is a plain "not found".
     *
     * @param array{variant_id: int, warehouse_id: int} $payload
     * @return array{Warehouse, ProductVariant}
     */
    private function locate(array $payload): array
    {
        $organizationId = (int) $this->context->organization()->getKey();
        $warehouse = Warehouse::query()->where('organization_id', $organizationId)->whereKey($payload['warehouse_id'])->first()
            ?? throw (new ModelNotFoundException)->setModel(Warehouse::class, [$payload['warehouse_id']]);
        $variant = ProductVariant::query()->where('organization_id', $organizationId)->whereKey($payload['variant_id'])->first()
            ?? throw (new ModelNotFoundException)->setModel(ProductVariant::class, [$payload['variant_id']]);

        return [$warehouse, $variant];
    }

    /**
     * The connection user, acting inside the connection organization (in memory
     * only: nothing is saved, the user's own active organization is untouched).
     */
    private function actor(): User
    {
        $user = clone $this->context->user();
        $user->active_organization_id = $this->context->organization()->getKey();

        return $user;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function binding(array $payload, string $onHand, string $reserved): array
    {
        return [
            'entity' => self::ENTITY,
            'target_id' => $payload['warehouse_id'].':'.$payload['variant_id'],
            'payload_hash' => $this->previews->hash($payload),
            'state_hash' => hash('sha256', InventoryQuantity::normalize($onHand).'|'.InventoryQuantity::normalize($reserved)),
            'required_scopes' => ['inventory.adjust'],
        ];
    }
}
