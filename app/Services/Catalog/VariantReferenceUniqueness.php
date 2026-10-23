<?php

namespace App\Services\Catalog;

use App\Models\ProductVariant;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Single authority for product-variant `reference` uniqueness (per organization),
 * shared by HTTP validation, MCP validation and the WooCommerce sync.
 *
 * Normalization: trimmed, blank → NULL. Comparison is plain equality on the
 * normalized value, the same comparison the `(organization_id, reference)`
 * unique index makes, so the column collation (case-insensitive
 * utf8mb4_unicode_ci) decides case semantics for the application check and the
 * database constraint alike — they cannot disagree.
 */
final class VariantReferenceUniqueness
{
    public function normalize(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * Normalizes `$data[$key]` in place before validation so the unique rule
     * checks the value that will actually be stored. Non-string input is left
     * untouched for the `string` rule to reject.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function prepare(array $data, string $key = 'reference'): array
    {
        if (array_key_exists($key, $data) && (is_string($data[$key]) || $data[$key] === null)) {
            $data[$key] = $this->normalize($data[$key]);
        }

        return $data;
    }

    /** Validation rule; the value it receives must already be normalized (see prepare()). */
    public function rule(int $organizationId, ?ProductVariant $ignore = null): Unique
    {
        return Rule::unique('product_variants', 'reference')->where('organization_id', $organizationId)->ignore($ignore);
    }

    /** True when another variant of the organization already holds this reference. */
    public function conflicts(int $organizationId, mixed $reference, ?int $ignoreVariantId = null): bool
    {
        $normalized = $this->normalize($reference);
        if ($normalized === null) {
            return false;
        }

        return ProductVariant::query()
            ->where('organization_id', $organizationId)
            ->where('reference', $normalized)
            ->when($ignoreVariantId !== null, fn ($query) => $query->whereKeyNot($ignoreVariantId))
            ->exists();
    }
}
