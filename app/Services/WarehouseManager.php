<?php

namespace App\Services;

use App\Enums\WarehouseStatus;
use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class WarehouseManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array{name: string, code: string, description?: string|null, status?: string} $data */
    public function create(User $actor, Organization $organization, array $data): Warehouse
    {
        return DB::transaction(function () use ($actor, $organization, $data) {
            $warehouse = new Warehouse;
            $warehouse->organization_id = $organization->getKey();
            $warehouse->name = $data['name'];
            $warehouse->code = $data['code'];
            $warehouse->description = $data['description'] ?? null;
            $warehouse->status = $data['status'] ?? WarehouseStatus::Active->value;
            $warehouse->save();
            $this->audit->record('warehouse.created', $actor, $organization, auditable: $warehouse, newValues: $warehouse->only(['name', 'code', 'description', 'status']));

            return $warehouse;
        });
    }

    /** @param array{name: string, code: string, description?: string|null, status: string} $data */
    public function update(User $actor, Warehouse $warehouse, array $data): Warehouse
    {
        return DB::transaction(function () use ($actor, $warehouse, $data) {
            $old = $warehouse->only(['name', 'code', 'description', 'status']);
            $warehouse->name = $data['name'];
            $warehouse->code = $data['code'];
            $warehouse->description = $data['description'] ?? null;
            $warehouse->status = $data['status'];
            $warehouse->save();
            $this->audit->record('warehouse.updated', $actor, $warehouse->organization, auditable: $warehouse, oldValues: $old, newValues: $warehouse->only(['name', 'code', 'description', 'status']));

            return $warehouse;
        });
    }
}
