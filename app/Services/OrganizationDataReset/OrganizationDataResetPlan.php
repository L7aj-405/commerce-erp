<?php

namespace App\Services\OrganizationDataReset;

use Carbon\CarbonImmutable;

final readonly class OrganizationDataResetPlan
{
    /**
     * @param list<string> $scopes
     * @param list<array{key:string,scope:string,table:string,action:string,column:?string}> $operations
     * @param array<string,int> $counts
     * @param list<array{code:string,message:string,count:int,required_scopes:list<string>}> $blockers
     * @param list<string> $preserved
     */
    public function __construct(
        public int $organizationId,
        public string $requestedScope,
        public array $scopes,
        public array $operations,
        public array $counts,
        public array $blockers,
        public array $preserved,
        public string $fingerprint,
        public CarbonImmutable $previewedAt,
    ) {}

    public function isExecutable(): bool
    {
        return $this->blockers === [];
    }

    public function totalAffected(): int
    {
        return array_sum($this->counts);
    }
}
