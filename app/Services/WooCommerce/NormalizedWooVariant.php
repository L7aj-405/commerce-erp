<?php

namespace App\Services\WooCommerce;

readonly class NormalizedWooVariant
{
    /** @param array<string, string> $attributes  e.g. ['Color' => 'Black', 'Size' => 'XL'] */
    public function __construct(
        public ?int $remoteVariationId,    // null for a simple product's implicit variant
        public string $status,
        public ?string $sku,
        public ?string $reference,
        public ?string $regularPrice,
        public ?string $salePrice,
        public ?string $effectivePrice,
        public ?string $taxClass,
        public bool $manageStock,
        public ?int $stockQuantity,
        public string $stockStatus,
        public ?string $weight,
        public ?string $imageUrl,
        public array $attributes,
    ) {}

    /** Human label from the selected attributes, e.g. "Black / XL". Never a fake SKU. */
    public function label(): ?string
    {
        $parts = array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            $this->attributes,
        ), fn ($value) => $value !== ''));

        return $parts === [] ? null : implode(' / ', $parts);
    }
}
