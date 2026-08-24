<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class ArchiveProductAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Product $product): void
    {
        DB::transaction(function () use ($actor, $product) {
            $oldStatus = $product->status->value;
            $product->status = CatalogStatus::Inactive;
            $product->save();
            $product->variants()->update(['status' => CatalogStatus::Inactive->value]);

            $this->audit->record(
                'product.archived',
                $actor,
                $product->organization,
                auditable: $product,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => CatalogStatus::Inactive->value],
            );
        });
    }
}
