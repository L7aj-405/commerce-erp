<?php

namespace App\Models\Concerns;

use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Sandbox;
use App\Services\Sandbox\SandboxContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

trait ScopesToSandbox
{
    public static function bootScopesToSandbox(): void
    {
        static::addGlobalScope('sandbox', function (Builder $query) {
            $id = app(SandboxContext::class)->id();
            $query->where($query->getModel()->qualifyColumn('sandbox_id'), $id);
        });
        static::creating(function ($model) {
            $context = app(SandboxContext::class);
            abort_if($context->isRestricted() && ! $context->isSandbox(), 403);
            $model->sandbox_id = $context->id();
            if ($context->isSandbox()) {
                abort_unless(Sandbox::query()->whereKey($context->id())->where('organization_id', $model->organization_id)->exists(), 403);
            }
        });
        static::saving(function ($model) {
            $context = app(SandboxContext::class);
            if ($model->exists) {
                abort_unless((string) $model->getRawOriginal('sandbox_id') === (string) $context->id(), 404);
                abort_if($model->isDirty('sandbox_id'), 403);
            }
            // `saving` fires before `creating`, so a new row's environment is the
            // request context (which `creating` then stamps).
            $environment = $model->exists ? $model->getRawOriginal('sandbox_id') : $context->id();
            if ($model instanceof Quotation && $model->converted_sales_order_id) {
                abort_unless(SalesOrder::query()->where('organization_id', $model->organization_id)
                    ->where('store_id', $model->store_id)->whereKey($model->converted_sales_order_id)->exists(), 404);
            }
            // Explicit ownership: a linked root owned by ANOTHER environment
            // (NULL = Production) is refused. The organization boundary stays
            // with the composite (organization_id, id) foreign keys.
            foreach ($model->sandboxLinks() as $column => $table) {
                $value = $model->getAttribute($column);
                if ($value === null || ($model->exists && ! $model->isDirty($column))) {
                    continue;
                }
                abort_if(DB::table($table)->where('id', $value)
                    ->where(fn ($query) => $environment === null
                        ? $query->whereNotNull('sandbox_id')
                        : $query->whereNull('sandbox_id')->orWhere('sandbox_id', '!=', $environment))
                    ->exists(), 404);
            }
            $allowed = $model->sandboxStatuses();
            if ($environment !== null && $allowed !== null) {
                $status = $model->status instanceof \BackedEnum ? $model->status->value : $model->status;
                abort_unless(in_array($status, $allowed, true), 403, 'TEST: transition indisponible.');
            }
        });
    }

    /**
     * Columns pointing at other sandbox-aware roots, as column => table.
     *
     * @return array<string, string>
     */
    public function sandboxLinks(): array
    {
        return [];
    }

    /**
     * Statuses a Test row may be persisted in; null when the model has no status.
     *
     * @return list<string>|null
     */
    public function sandboxStatuses(): ?array
    {
        return ['draft'];
    }
}
