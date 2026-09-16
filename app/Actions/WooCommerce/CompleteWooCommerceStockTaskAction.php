<?php

namespace App\Actions\WooCommerce;

use App\Enums\WooCommerceStockTaskStatus;
use App\Models\User;
use App\Models\WooCommerceStockTask;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Marquer comme mis à jour" — records that a human has reflected the ERP
 * stock change on WooCommerce by hand. CRITICAL: this NEVER creates an
 * InventoryLedger/InventoryMovement entry and never calls the WooCommerce
 * API — it only stamps who/when for audit (§8, §9).
 */
class CompleteWooCommerceStockTaskAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, WooCommerceStockTask $task): WooCommerceStockTask
    {
        abort_unless($actor->hasPermission($task->organization_id, 'integrations.woocommerce.stock_tasks.manage'), 403);

        return DB::transaction(function () use ($actor, $task) {
            $task = WooCommerceStockTask::query()->whereKey($task->getKey())
                ->where('organization_id', $task->organization_id)->lockForUpdate()->firstOrFail();

            if ($task->status === WooCommerceStockTaskStatus::Completed) {
                // Double-click / retried request on an already-completed task:
                // safely idempotent, not an error (§19).
                return $task;
            }

            if ($task->status !== WooCommerceStockTaskStatus::Pending) {
                throw ValidationException::withMessages(['task' => 'Seule une tâche à faire peut être marquée comme mise à jour.']);
            }

            $task->status = WooCommerceStockTaskStatus::Completed;
            $task->completed_at = now();
            $task->completed_by_user_id = $actor->getKey();
            $task->completed_via = 'manual';
            $task->save();

            $this->audit->record('woocommerce_stock_task.completed', $actor, $task->organization, $task->store, $task, oldValues: [
                'status' => WooCommerceStockTaskStatus::Pending->value,
            ], newValues: [
                'status' => WooCommerceStockTaskStatus::Completed->value,
                'product_variant_id' => $task->product_variant_id,
                'quantity_delta' => $task->quantity_delta,
            ]);

            return $task;
        });
    }
}
