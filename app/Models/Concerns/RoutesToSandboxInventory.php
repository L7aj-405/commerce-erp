<?php

namespace App\Models\Concerns;

use App\Services\Sandbox\SandboxContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 4/5 — environment-routed ledger models (inventory + finance).
 *
 * In Production the model uses its normal table (inventory_balances, …). While
 * a Test context is active it uses the physically separate `sandbox_` table
 * (sandbox_inventory_balances, …), scoped to the active sandbox. All existing
 * locking, allocation, reservation and transfer logic therefore runs unchanged
 * against Test tables, and a Test request can never write a Production
 * inventory row (and vice versa): every write is checked against the table
 * the row lives in.
 *
 * A hydrated instance keeps the table it was loaded from (Eloquent copies it
 * through setTable()), so a row never silently changes environment.
 */
trait RoutesToSandboxInventory
{
    public static function bootRoutesToSandboxInventory(): void
    {
        static::creating(function ($model) {
            $context = app(SandboxContext::class);
            if (static::isSandboxTable($model->getTable())) {
                abort_unless($context->isSandbox(), 403, 'Les données test ne sont accessibles qu’en mode test.');
                $model->sandbox_id = $context->id();
            } else {
                abort_if($context->isRestricted(), 403, 'Les données de Production ne sont jamais modifiées en mode test.');
            }
        });

        // Phase 5: a row pointing at a sales order must live in that order's
        // environment (a Test payment / refund / commission / collection only
        // ever references a Test order of the same sandbox, and vice versa).
        static::saving(function ($model) {
            $column = $model->sandboxSalesOrderColumn();
            if ($column === null || $model->getAttribute($column) === null || ($model->exists && ! $model->isDirty($column))) {
                return;
            }
            $orderSandbox = DB::table('sales_orders')->where('id', $model->getAttribute($column))->value('sandbox_id');
            $expected = static::isSandboxTable($model->getTable()) ? app(SandboxContext::class)->id() : null;
            abort_unless((string) $orderSandbox === (string) $expected, 404);
        });

        $sameEnvironment = function ($model) {
            $context = app(SandboxContext::class);
            if (static::isSandboxTable($model->getTable())) {
                abort_unless($context->isSandbox() && (string) $model->getRawOriginal('sandbox_id', $model->sandbox_id) === (string) $context->id(), 404);
                abort_if($model->exists && $model->isDirty('sandbox_id'), 403);
            } else {
                abort_if($context->isRestricted(), 403, 'Les données de Production ne sont jamais modifiées en mode test.');
            }
        };
        static::updating($sameEnvironment);
        static::deleting($sameEnvironment);
    }

    /**
     * Sandbox ownership is part of the BASE query (not a global scope), so
     * `withoutGlobalScopes()` can never widen a Test query beyond the active
     * sandbox. Reads, relations, updates and deletes all start here.
     */
    public function newModelQuery()
    {
        $query = parent::newModelQuery();
        if (static::isSandboxTable($this->getTable())) {
            $query->where($this->qualifyColumn('sandbox_id'), app(SandboxContext::class)->id());
        }

        return $query;
    }

    /** Column holding a sales order id that must share this row's environment (null = none). */
    public function sandboxSalesOrderColumn(): ?string
    {
        return null;
    }

    public function getTable()
    {
        if ($this->table !== null) {
            return $this->table;
        }

        $production = Str::snake(Str::pluralStudly(class_basename($this)));

        return app(SandboxContext::class)->isSandbox() ? 'sandbox_'.$production : $production;
    }

    public function isSandboxInventory(): bool
    {
        return static::isSandboxTable($this->getTable());
    }

    /** The table this model uses in the CURRENT environment (for raw joins / qualified columns). */
    public static function environmentTable(): string
    {
        return (new static)->getTable();
    }

    protected static function isSandboxTable(string $table): bool
    {
        return str_starts_with($table, 'sandbox_');
    }
}
