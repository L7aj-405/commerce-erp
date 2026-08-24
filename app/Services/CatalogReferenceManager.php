<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\TaxRate;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CatalogReferenceManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function save(User $actor, Organization $organization, Model $record, array $data, string $event): Model
    {
        $oldValues = $record->exists ? $record->getAttributes() : [];
        $record->organization_id = $organization->getKey();
        $record->name = $data['name'];
        $record->status = $data['status'];

        if ($record instanceof Brand || $record instanceof Category) {
            $record->slug = $data['slug'];
        }
        if ($record instanceof Category) {
            $record->parent_id = $data['parent_id'] ?? null;
        }
        if ($record instanceof UnitOfMeasure) {
            $record->symbol = $data['symbol'];
        }
        if ($record instanceof TaxRate) {
            $record->rate = $data['rate'];
        }

        $record->save();
        $this->audit->record(
            $event,
            $actor,
            $organization,
            auditable: $record,
            oldValues: collect($oldValues)->only(['name', 'slug', 'parent_id', 'symbol', 'rate', 'status'])->all(),
            newValues: $record->only(['name', 'slug', 'parent_id', 'symbol', 'rate', 'status']),
        );

        return $record;
    }
}
