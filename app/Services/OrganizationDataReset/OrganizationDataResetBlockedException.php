<?php

namespace App\Services\OrganizationDataReset;

class OrganizationDataResetBlockedException extends OrganizationDataResetException
{
    /** @param list<array{code:string,message:string,count:int,required_scopes:list<string>}> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('The organization data reset is blocked by cross-domain dependencies.');
    }
}
