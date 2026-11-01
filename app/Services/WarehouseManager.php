<?php

namespace App\Services;

use App\Enums\WarehouseStatus;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            $this->applyStoreLink($warehouse, $data);
            $warehouse->save();
            $this->audit->record('warehouse.created', $actor, $organization, auditable: $warehouse, newValues: $warehouse->only(['name', 'code', 'description', 'status', 'store_id', 'allow_direct_sales']));

            return $warehouse;
        });
    }

    /** @param array{name: string, code: string, description?: string|null, status: string} $data */
    public function update(User $actor, Warehouse $warehouse, array $data): Warehouse
    {
        return DB::transaction(function () use ($actor, $warehouse, $data) {
            $old = $warehouse->only(['name', 'code', 'description', 'status', 'store_id', 'allow_direct_sales']);
            $warehouse->name = $data['name'];
            $warehouse->code = $data['code'];
            $warehouse->description = $data['description'] ?? null;
            $warehouse->status = $data['status'] ?? $warehouse->status;
            $this->applyStoreLink($warehouse, $data);
            $warehouse->save();
            $this->audit->record('warehouse.updated', $actor, $warehouse->organization, auditable: $warehouse, oldValues: $old, newValues: $warehouse->only(['name', 'code', 'description', 'status', 'store_id', 'allow_direct_sales']));

            return $warehouse;
        });
    }

    /**
     * Store association and the direct-sales exception. Stock never moves:
     * the location keeps its balances whatever Store it is attached to. A
     * location linked to a Store is sold from through that Store, so the
     * depot-only `allow_direct_sales` flag is cleared. A location cannot leave
     * a Store that still uses it as its default POS location.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyStoreLink(Warehouse $warehouse, array $data): void
    {
        if (array_key_exists('store_id', $data)) {
            $storeId = $data['store_id'] !== null ? (int) $data['store_id'] : null;
            if ($warehouse->exists && $storeId !== ($warehouse->store_id !== null ? (int) $warehouse->store_id : null)) {
                $usedBy = Store::query()->where('organization_id', $warehouse->organization_id)
                    ->where('default_warehouse_id', $warehouse->getKey())
                    ->when($storeId !== null, fn ($q) => $q->whereKeyNot($storeId))
                    ->value('name');
                if ($usedBy !== null) {
                    throw ValidationException::withMessages([
                        'store_id' => "Cet emplacement est l’emplacement de vente par défaut du magasin « {$usedBy} ». Changez d’abord ce réglage.",
                    ]);
                }
            }
            $warehouse->store_id = $storeId;
        }
        if (array_key_exists('allow_direct_sales', $data)) {
            $warehouse->allow_direct_sales = (bool) $data['allow_direct_sales'];
        }
        if ($warehouse->store_id !== null) {
            $warehouse->allow_direct_sales = false;
        }
    }
}
