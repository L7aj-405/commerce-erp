<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StoreCreator
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $settings */
    public function create(User $actor, Organization $organization, string $name, string $code, array $settings = []): Store
    {
        abort_unless($actor->hasPermission($organization, 'stores.create'), 403);

        return DB::transaction(function () use ($actor, $organization, $name, $code, $settings) {
            $store = new Store;
            $store->organization_id = $organization->getKey();
            $store->name = $name;
            $store->code = $code;
            $store->status = 'active';
            $store->settings = $settings;
            $store->save();

            $membership = new StoreMembership;
            $membership->organization_id = $organization->getKey();
            $membership->store_id = $store->getKey();
            $membership->user_id = $actor->getKey();
            $membership->save();

            if ($actor->active_organization_id === $organization->getKey() && ! $actor->active_store_id) {
                $actor->active_store_id = $store->getKey();
                $actor->save();
            }

            $this->audit->record(
                'store.created',
                $actor,
                $organization,
                $store,
                $store,
                newValues: ['name' => $name, 'code' => $code, 'settings' => $settings],
            );

            return $store;
        });
    }
}
