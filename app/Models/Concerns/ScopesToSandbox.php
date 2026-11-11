<?php

namespace App\Models\Concerns;

use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Sandbox;
use App\Services\Sandbox\SandboxContext;
use Illuminate\Database\Eloquent\Builder;

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
            if ($model->exists) {
                abort_unless((string) $model->getRawOriginal('sandbox_id') === (string) app(SandboxContext::class)->id(), 404);
                abort_if($model->isDirty('sandbox_id'), 403);
            }
            if ($model instanceof Quotation && $model->converted_sales_order_id) {
                abort_unless(SalesOrder::query()->where('organization_id', $model->organization_id)
                    ->where('store_id', $model->store_id)->whereKey($model->converted_sales_order_id)->exists(), 404);
            }
            if ($model->sandbox_id !== null) {
                $status = $model->status instanceof \BackedEnum ? $model->status->value : $model->status;
                abort_unless($status === 'draft' || ($model instanceof Quotation && $status === 'converted'), 403, 'TEST: transition indisponible.');
            }
        });
    }
}
