<?php

namespace App\Models\Concerns;

use App\Models\Builders\SandboxLedgerBuilder;
use App\Services\Sandbox\SandboxContext;
use App\Services\Sandbox\SandboxScopeColumn;
use Illuminate\Database\Eloquent\Builder;
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
        // Sandbox ownership as a NON-REMOVABLE global scope (see SandboxLedgerBuilder):
        // grouped correctly with caller OR clauses and resolved against the query's
        // current FROM at compile time, so aliased queries keep working.
        static::addGlobalScope(SandboxLedgerBuilder::SCOPE, function (Builder $query) {
            if (static::isSandboxTable($query->getModel()->getTable())) {
                $query->where(SandboxScopeColumn::for($query), app(SandboxContext::class)->id());
            }
        });

        static::creating(function ($model) {
            // A record keeps the table of the environment that created it (like a
            // hydrated one), so it can never silently change environment later.
            $model->setTable($model->getTable());
            $context = app(SandboxContext::class);
            if (static::isSandboxTable($model->getTable())) {
                abort_unless($context->isSandbox(), 403, 'Les données test ne sont accessibles qu’en mode test.');
                $model->sandbox_id = $context->id();
            } else {
                abort_if($context->isRestricted(), 403, 'Les données de Production ne sont jamais modifiées en mode test.');
            }
        });

        // Phases 5-6: a row pointing at a sales order / quotation (single tables
        // that carry sandbox_id) must live in that record's environment — a Test
        // payment, commission, collection, procurement requirement, purchase order
        // line or sourcing request only ever references records of the same
        // sandbox, and a Production row only Production records.
        static::saving(function ($model) {
            $links = $model->sandboxRootLinks();
            if (($column = $model->sandboxSalesOrderColumn()) !== null) {
                $links[$column] = 'sales_orders';
            }
            $expected = static::isSandboxTable($model->getTable()) ? app(SandboxContext::class)->id() : null;
            foreach ($links as $column => $table) {
                $value = $model->getAttribute($column);
                if ($value === null || ($model->exists && ! $model->isDirty($column))) {
                    continue;
                }
                $sandbox = DB::table($table)->where('id', $value)->value('sandbox_id');
                abort_unless((string) $sandbox === (string) $expected, 404);
            }
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

    public function newEloquentBuilder($query)
    {
        return new SandboxLedgerBuilder($query);
    }

    /**
     * Other single-table roots this row references, as column => table (each
     * has a sandbox_id column and must share this row's environment).
     *
     * @return array<string, string>
     */
    public function sandboxRootLinks(): array
    {
        return [];
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

    /**
     * Phase 10.1 — a Test-row insert / update always runs in a short transaction
     * (a savepoint when one is already open), so SandboxGenerationFence's shared
     * lock on the sandbox row is held until the write commits: a concurrent
     * reset either runs after it (and deletes the row) or makes it fail on the
     * new generation. Production saves are untouched.
     */
    public function save(array $options = []): bool
    {
        if (! \App\Services\Sandbox\SandboxGenerationFence::active()) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(fn () => parent::save($options));
    }
}
