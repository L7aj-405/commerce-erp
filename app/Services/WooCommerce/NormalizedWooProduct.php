<?php

namespace App\Services\WooCommerce;

/**
 * A stable internal representation of one WooCommerce product, decoupled from the
 * raw Woo JSON shape (which varies by version and plugins). The sync engine only
 * ever reads from these DTOs — never from the JSON directly.
 */
readonly class NormalizedWooProduct
{
    /**
     * @param  list<NormalizedWooVariant>  $variants
     * @param  list<NormalizedWooCategory>  $categories
     * @param  list<string>  $tags
     */
    public function __construct(
        public int $remoteId,
        public string $type,               // simple | variable | grouped | external
        public string $status,             // publish | draft | pending | private
        public string $name,
        public ?string $slug,
        public ?string $permalink,
        public ?string $catalogVisibility,
        public bool $featured,
        public ?string $description,
        public ?string $shortDescription,
        public ?string $sku,               // '' / whitespace normalised to null
        public ?string $reference,         // from a configured meta key, else null
        public ?string $regularPrice,      // DECIMAL(19,4) string or null
        public ?string $salePrice,
        public ?string $effectivePrice,    // current selling price
        public string $taxStatus,          // taxable | shipping | none
        public ?string $taxClass,          // '' == standard
        public bool $manageStock,
        public ?int $stockQuantity,
        public string $stockStatus,        // instock | outofstock | onbackorder
        public ?string $backorders,
        public ?string $weight,
        public ?string $shippingClass,
        public ?string $brandName,
        public ?string $imageUrl,
        public ?string $dateCreated,
        public ?string $dateModified,
        public bool $virtual,
        public bool $downloadable,
        public bool $onSale,
        public array $variants,
        public array $categories,
        public array $tags,
    ) {}

    public function isVariable(): bool
    {
        return $this->type === 'variable';
    }

    /** ERP status: only published Woo products are sellable; others sync but stay inactive. */
    public function erpStatus(): string
    {
        return $this->status === 'publish' ? 'active' : 'inactive';
    }
}
