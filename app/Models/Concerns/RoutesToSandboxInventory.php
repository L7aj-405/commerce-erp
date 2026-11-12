<?php

namespace App\Models\Concerns;

use App\Services\Sandbox\SandboxContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Phase 4 — environment-routed inventory ledger models.
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
        static::addGlobalScope('sandbox_inventory', function (Builder $query) {
            if (static::isSandboxTable($query->getModel()->getTable())) {
                $query->where($query->getModel()->qualifyColumn('sandbox_id'), app(SandboxContext::class)->id());
            }
        });

        static::creating(function ($model) {
            $context = app(SandboxContext::class);
            if (static::isSandboxTable($model->getTable())) {
                abort_unless($context->isSandbox(), 403, 'Le stock test n’est accessible qu’en mode test.');
                $model->sandbox_id = $context->id();
            } else {
                abort_if($context->isRestricted(), 403, 'Le stock de Production n’est jamais modifié en mode test.');
            }
        });

        $sameEnvironment = function ($model) {
            $context = app(SandboxContext::class);
            if (static::isSandboxTable($model->getTable())) {
                abort_unless($context->isSandbox() && (string) $model->getRawOriginal('sandbox_id', $model->sandbox_id) === (string) $context->id(), 404);
                abort_if($model->exists && $model->isDirty('sandbox_id'), 403);
            } else {
                abort_if($context->isRestricted(), 403, 'Le stock de Production n’est jamais modifié en mode test.');
            }
        };
        static::updating($sameEnvironment);
        static::deleting($sameEnvironment);
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
