<?php

namespace App\Services\WooCommerce;

readonly class NormalizedWooCategory
{
    public function __construct(
        public int $remoteId,
        public string $name,
        public ?int $remoteParentId,
    ) {}
}
