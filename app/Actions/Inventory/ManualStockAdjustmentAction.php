<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Enums\InventoryMovementType;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryBalanceLocker;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Set / Add / Remove" manual stock correction, shared by the web dialog and MCP.
 *
 * It only translates the operator's intent (exact quantity, or a +/- amount) into
 * the signed delta the ERP already understands, then delegates the mutation to
 * AdjustInventoryAction (balance lock, no negative stock, reservations respected,
 * one immutable InventoryMovement, audit). Balances are never written here.
 */
class ManualStockAdjustmentAction
{
    use AuthorizesInventoryAction;

    public const SET = 'set';

    public const INCREASE = 'increase';

    public const DECREASE = 'decrease';

    public const OPERATIONS = [self::SET, self::INCREASE, self::DECREASE];

    public function __construct(private readonly InventoryBalanceLocker $balances, private readonly AdjustInventoryAction $adjust) {}

    /**
     * Read-only. Never locks, never writes.
     *
     * @return array<string, mixed>
     */
    public function preview(User $actor, Organization $organization, Warehouse $warehouse, ProductVariant $variant, string $operation, int|float|string $quantity, ?string $reason = null): array
    {
        $this->authorizeInventory($actor, $organization, 'inventory.adjust');
        $this->validateInventoryIdentity($organization, $warehouse, $variant);

        $balance = InventoryBalance::query()
            ->where('organization_id', $organization->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->first();

        $plan = $this->plan($balance?->on_hand ?? InventoryQuantity::ZERO, $balance?->reserved ?? InventoryQuantity::ZERO, $operation, $quantity);
        if ($reason !== null && trim($reason) === '') {
            $plan['issues'][] = ['code' => 'reason_required', 'field' => 'reason', 'message' => 'A reason is required.'];
        }

        return $plan + ['valid' => $plan['issues'] === []];
    }

    /**
     * @return InventoryMovement the single movement created
     *
     * @throws ValidationException when invalid, or when stock changed since $expectedOnHand was shown
     */
    public function execute(User $actor, Organization $organization, Warehouse $warehouse, ProductVariant $variant, string $operation, int|float|string $quantity, string $reason, ?string $reference, string $expectedOnHand): InventoryMovement
    {
        $this->authorizeInventory($actor, $organization, 'inventory.adjust');
        $this->validateInventoryIdentity($organization, $warehouse, $variant);

        return DB::transaction(function () use ($actor, $organization, $warehouse, $variant, $operation, $quantity, $reason, $reference, $expectedOnHand) {
            $balance = $this->balances->lock($organization, $warehouse, $variant);
            if (InventoryQuantity::compare($balance->on_hand, $expectedOnHand) !== 0) {
                throw ValidationException::withMessages(['expected_on_hand' => 'Stock changed since it was displayed. Review the new quantity and confirm again.']);
            }

            $plan = $this->plan($balance->on_hand, $balance->reserved, $operation, $quantity);
            if ($plan['issues'] !== []) {
                $issue = $plan['issues'][0];
                throw ValidationException::withMessages([$issue['field'] => $issue['message']]);
            }

            return $this->adjust->execute(
                $actor, $organization, $warehouse, $variant,
                $plan['delta_sign'] > 0 ? InventoryMovementType::AdjustmentIn : InventoryMovementType::AdjustmentOut,
                $plan['delta_abs'], $reason, $reference,
            );
        });
    }

    /**
     * Pure computation, the single source of the delta and of the pre-checks
     * (the same rules AdjustInventoryAction enforces at execution).
     *
     * @return array<string, mixed>
     */
    public function plan(string $onHand, string $reserved, string $operation, int|float|string $quantity): array
    {
        $issues = [];
        $available = InventoryQuantity::subtract($onHand, $reserved);
        $delta = InventoryQuantity::ZERO;
        $after = $onHand;

        if (! in_array($operation, self::OPERATIONS, true)) {
            $issues[] = ['code' => 'invalid_operation', 'field' => 'operation', 'message' => 'Unknown stock operation.'];
        }

        try {
            $quantity = InventoryQuantity::normalize($quantity);
        } catch (ValidationException $exception) {
            $quantity = null;
            $issues[] = ['code' => 'invalid_quantity', 'field' => 'quantity', 'message' => (string) collect($exception->errors())->flatten()->first()];
        }

        if ($issues === []) {
            $zero = InventoryQuantity::ZERO;
            if (InventoryQuantity::compare($quantity, $zero) < 0) {
                $issues[] = ['code' => 'negative_quantity', 'field' => 'quantity', 'message' => 'The quantity cannot be negative.'];
            } elseif ($operation !== self::SET && InventoryQuantity::compare($quantity, $zero) === 0) {
                $issues[] = ['code' => 'invalid_quantity', 'field' => 'quantity', 'message' => 'The quantity must be greater than zero.'];
            } else {
                $delta = match ($operation) {
                    self::SET => InventoryQuantity::subtract($quantity, $onHand),
                    self::INCREASE => $quantity,
                    self::DECREASE => InventoryQuantity::subtract($zero, $quantity),
                };
                $after = InventoryQuantity::add($onHand, $delta);

                if (InventoryQuantity::compare($after, $zero) < 0) {
                    $issues[] = ['code' => 'negative_result', 'field' => 'quantity', 'message' => 'The resulting stock cannot be negative.'];
                } elseif (InventoryQuantity::compare($delta, $zero) < 0 && InventoryQuantity::compare($available, ltrim($delta, '-')) < 0) {
                    $issues[] = ['code' => 'exceeds_available', 'field' => 'quantity', 'message' => 'The requested quantity exceeds available stock (reserved stock cannot be removed).'];
                } elseif (InventoryQuantity::compare($delta, $zero) === 0) {
                    $issues[] = ['code' => 'no_change', 'field' => 'quantity', 'message' => 'The resulting stock equals the current stock.'];
                }
            }
        }

        return [
            'operation' => $operation,
            'on_hand_before' => $onHand,
            'reserved' => $reserved,
            'available_before' => $available,
            'delta' => $delta,
            'on_hand_after' => $after,
            'available_after' => InventoryQuantity::subtract($after, $reserved),
            'delta_sign' => str_starts_with($delta, '-') ? -1 : (InventoryQuantity::compare($delta, InventoryQuantity::ZERO) === 0 ? 0 : 1),
            'delta_abs' => ltrim($delta, '-'),
            'issues' => $issues,
        ];
    }
}
