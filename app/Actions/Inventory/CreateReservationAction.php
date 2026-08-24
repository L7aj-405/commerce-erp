<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryReservationManager;

class CreateReservationAction
{
    use AuthorizesInventoryAction;

    public function __construct(private readonly InventoryReservationManager $manager) {}

    public function execute(
        User $actor,
        Organization $organization,
        Warehouse $warehouse,
        ProductVariant $variant,
        int|float|string $quantity,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $reference = null,
        mixed $expiresAt = null,
    ): InventoryReservation {
        $this->authorizeInventory($actor, $organization, 'inventory.reserve');
        abort_if($referenceType === SalesOrderInventoryAllocation::class, 403, 'Sales reservations must be created through Sales confirmation.');
        $this->validateInventoryIdentity($organization, $warehouse, $variant);

        return $this->manager->reserve($actor, $organization, $warehouse, $variant, $quantity, $referenceType, $referenceId, $reference, $expiresAt);
    }
}
