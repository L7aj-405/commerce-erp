<?php

namespace App\Services\OrganizationDataReset;

class OrganizationDataResetAlreadyExecutedException extends OrganizationDataResetException
{
    public function __construct()
    {
        parent::__construct('This reset preview has already been executed.');
    }
}
