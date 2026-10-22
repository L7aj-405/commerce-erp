<?php

namespace App\Services\OrganizationDataReset;

final readonly class OrganizationDataResetResult
{
    /** @param array<string,int> $affected */
    public function __construct(
        public int $organizationId,
        public string $scope,
        public array $affected,
        public int $auditLogId,
    ) {}
}
