<?php

namespace App\Services\OrganizationDataReset;

final readonly class OrganizationDataResetBlocker
{
    /** @param list<string> $requiredScopes */
    public function __construct(
        public string $code,
        public string $message,
        public int $count,
        public array $requiredScopes = [],
    ) {}

    /** @return array{code:string,message:string,count:int,required_scopes:list<string>} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'count' => $this->count,
            'required_scopes' => $this->requiredScopes,
        ];
    }
}
