<?php

namespace App\Services\OrganizationDataReset;

use App\Models\Organization;

class OrganizationDataResetPreviewService
{
    public function __construct(private readonly OrganizationDataResetPlanner $planner) {}

    public function preview(Organization $organization, OrganizationDataResetScope $scope): OrganizationDataResetPlan
    {
        return $this->planner->plan($organization, $scope);
    }
}
