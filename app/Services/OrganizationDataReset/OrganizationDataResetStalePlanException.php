<?php

namespace App\Services\OrganizationDataReset;

class OrganizationDataResetStalePlanException extends OrganizationDataResetException
{
    public function __construct()
    {
        parent::__construct('The reset preview is stale; generate and approve a new preview.');
    }
}
