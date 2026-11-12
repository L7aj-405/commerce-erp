<?php

namespace App\Services\Sandbox;

use App\Models\Sandbox;
use Illuminate\Support\Facades\DB;

/**
 * One explicit point-in-time snapshot of Production stock into the Test
 * inventory layer, taken once per sandbox (on entering Test Mode, or on the
 * first Test request of a sandbox created before Phase 4).
 *
 * - Copies Production `on_hand` per (warehouse, variant). Test `reserved`
 *   starts at 0: Production reservations belong to Production orders that do
 *   not exist in the sandbox.
 * - Writes one `opening` movement per copied balance (reference_type
 *   `sandbox_initialization`) so the Test ledger reconciles with its balances.
 * - Idempotent and race-safe: the sandbox row is locked FOR UPDATE and
 *   `inventory_initialized_at` is re-checked under the lock; the unique
 *   (sandbox, organization, warehouse, variant) key backs it up.
 * - After the snapshot the sandbox evolves independently: later Production
 *   movements never rewrite Test balances. Stock that did not exist at
 *   snapshot time (new warehouse / variant) starts at 0 in Test.
 */
final class SandboxInventoryInitializer
{
    public function ensure(Sandbox $sandbox): void
    {
        if ($sandbox->inventory_initialized_at !== null) {
            return;
        }

        DB::transaction(function () use ($sandbox) {
            $locked = Sandbox::query()->whereKey($sandbox->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->inventory_initialized_at !== null) {
                $sandbox->inventory_initialized_at = $locked->inventory_initialized_at;

                return;
            }
            $now = now();
            SandboxInventoryGuard::bypass(function () use ($locked, $now) {
                $source = fn () => DB::table('inventory_balances')
                    ->where('organization_id', $locked->organization_id)
                    ->where('on_hand', '>', 0);

                DB::table('sandbox_inventory_balances')->insertUsing(
                    ['sandbox_id', 'organization_id', 'warehouse_id', 'product_variant_id', 'on_hand', 'reserved', 'created_at', 'updated_at'],
                    $source()->selectRaw('?, organization_id, warehouse_id, product_variant_id, on_hand, 0, ?, ?', [$locked->getKey(), $now, $now]),
                );
                DB::table('sandbox_inventory_movements')->insertUsing(
                    ['sandbox_id', 'organization_id', 'warehouse_id', 'product_variant_id', 'movement_type', 'quantity',
                        'quantity_before', 'quantity_after', 'reference_type', 'reference_id', 'reason', 'created_at'],
                    $source()->selectRaw("?, organization_id, warehouse_id, product_variant_id, 'opening', on_hand, 0, on_hand, 'sandbox_initialization', id, ?, ?",
                        [$locked->getKey(), 'Initialisation du stock test depuis la Production', $now]),
                );
            });
            $locked->inventory_initialized_at = $now;
            $locked->save();
            $sandbox->inventory_initialized_at = $now;
        });
    }
}
