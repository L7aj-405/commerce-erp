<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\User;
use App\Services\InventoryReservationManager;

class ReleaseReservationAction
{
    use AuthorizesInventoryAction;

    public function __construct(private readonly InventoryReservationManager $manager) {}

    public function execute(User $actor, Organization $organization, InventoryReservation $reservation): InventoryReservation
    {
        $this->authorizeInventory($actor, $organization, 'inventory.release');
        abort_if($reservation->reference_type === SalesOrderInventoryAllocation::class, 403, 'Sales reservations must be released through the Sales lifecycle.');

        return $this->manager->release($actor, $organization, $reservation);
    }
}
