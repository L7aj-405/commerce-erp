<?php

namespace App\Services\Auth;

final readonly class GoogleAuthIdentity
{
    public function __construct(
        public string $sub,
        public string $email,
        public bool $emailVerified,
        public ?string $name,
        public ?string $avatarUrl,
    ) {}
}
