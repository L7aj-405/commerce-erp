<?php

namespace App\Services\Mcp\Products;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\CatalogProductDataValidator;
use App\Services\Catalog\WooCommerceProductFieldOwnership;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class McpProductChangeService
{
    public const PRODUCT_FIELDS = ['name', 'description', 'image_url', 'brand_id', 'category_id', 'unit_id', 'status'];
    public const VARIANT_FIELDS = [
        'label', 'sku', 'reference', 'barcode', 'image_url', 'public_price_ttc', 'unit_price_ht',
        'regular_sale_price', 'promotional_sale_price', 'default_sale_price', 'tax_rate_id', 'status',
    ];
    public const CREATE_VARIANT_FIELDS = [
        'label', 'sku', 'reference', 'barcode', 'image_url', 'purchase_price', 'public_price_ttc', 'unit_price_ht',
        'regular_sale_price', 'promotional_sale_price', 'default_sale_price', 'tax_rate_id', 'status',
    ];

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly CatalogProductDataValidator $validator,
        private readonly WooCommerceProductFieldOwnership $wooOwnership,
        private readonly McpIdempotencyService $canonical,
    ) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function validate(string $entityType, string $mode, ?int $targetId, array $data): array
    {
        if (! in_array($entityType, ['product', 'variant'], true) || ! in_array($mode, ['create', 'update'], true)) {
            throw new InvalidArgumentException('invalid_validation_mode');
        }
        $forbidden = $this->forbiddenFields($entityType, $mode, $data);
        if ($mode === 'create' && ! $this->context->allows('product_cost.manage')) {
            if ($entityType === 'variant' && array_key_exists('purchase_price', $data)) {
                $forbidden[] = 'purchase_price';
            }
            if ($entityType === 'product' && is_array($data['variant'] ?? null) && array_key_exists('purchase_price', $data['variant'])) {
                $forbidden[] = 'variant.purchase_price';
            }
            $forbidden = array_values(array_unique($forbidden));
        }
        $wooFields = [];

        try {
            if ($entityType === 'product') {
                if ($mode === 'create') {
                    $normalized = $this->validator->product($data, $this->organizationId(), true, $this->context->allows('product_cost.manage'));
                } else {
                    $product = $this->product($targetId);
                    $wooFields = $this->wooOwnership->productConflicts($product, array_keys($data));
                    $normalized = $this->validator->product(
                        array_replace($this->productCurrent($product), $this->only($data, self::PRODUCT_FIELDS)),
                        $this->organizationId(),
                        false,
                        false,
                    );
                }
            } elseif ($mode === 'create') {
                $normalized = $this->validator->variant($data, $this->organizationId(), null, $this->context->allows('product_cost.manage'));
            } else {
                $variant = $this->variant($targetId);
                $wooFields = $this->wooOwnership->variantConflicts($variant, array_keys($data));
                $normalized = $this->validator->variant(
                    array_replace($this->variantCurrent($variant, includeCost: false), $this->only($data, self::VARIANT_FIELDS)),
                    $this->organizationId(),
                    $variant,
                    false,
                );
            }

            return [
                'valid' => $forbidden === [] && $wooFields === [],
                'normalized' => $normalized,
                'errors' => [],
                'missing_references' => [],
                'duplicate_identifiers' => [],
                'invalid_decimals' => [],
                'forbidden_fields' => $forbidden,
                'woo_managed_fields' => $wooFields,
            ];
        } catch (ValidationException $exception) {
            return [
                'valid' => false,
                'normalized' => [],
                'errors' => $exception->errors(),
                'missing_references' => $this->failedFields($exception, ['Exists']),
                'duplicate_identifiers' => $this->failedFields($exception, ['Unique']),
                'invalid_decimals' => $this->failedFields($exception, ['Numeric', 'Min', 'Decimal'], ['purchase_price', 'public_price_ttc', 'unit_price_ht', 'regular_sale_price', 'promotional_sale_price', 'default_sale_price']),
                'forbidden_fields' => $forbidden,
                'woo_managed_fields' => $wooFields,
            ];
        }
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function preview(string $targetType, int $targetId, array $changes): array
    {
        [$current, $proposed, $model, $scope, $forbidden, $wooFields] = $this->prepare($targetType, $targetId, $changes);
        $this->context->authorize($scope);
        $changed = [];
        $unchanged = [];
        foreach ($proposed as $field => $value) {
            if ($this->same($current[$field] ?? null, $value)) {
                $unchanged[] = $field;
            } else {
                $changed[] = $field;
            }
        }
        $conflicts = [];
        if ($forbidden !== []) $conflicts[] = 'forbidden_fields';
        if ($wooFields !== []) $conflicts[] = 'woocommerce_managed_fields';

        $fingerprint = null;
        $expiresAt = null;
        if ($conflicts === [] && $changed !== []) {
            $fingerprint = bin2hex(random_bytes(32));
            $expiresAt = now()->addSeconds((int) config('mcp.preview_ttl_seconds', 300));
            Cache::put($this->previewKey($fingerprint), [
                'organization_id' => $this->organizationId(),
                'connection_id' => (string) $this->context->connectionOrFail()->getKey(),
                'target_type' => $targetType,
                'target_id' => $targetId,
                'current_version' => $this->version($model),
                'current_hash' => hash('sha256', $this->canonical->canonicalJson($current)),
                'proposed_hash' => hash('sha256', $this->canonical->canonicalJson($proposed)),
                'required_scope' => $scope,
            ], $expiresAt);
        }

        return [
            'target' => ['type' => $targetType, 'id' => $targetId],
            'current_values' => $current,
            'proposed_values' => $proposed,
            'changed_fields' => $changed,
            'unchanged_fields' => $unchanged,
            'conflicts' => $conflicts,
            'forbidden_fields' => $forbidden,
            'woo_managed_fields' => $wooFields,
            'current_version' => $this->version($model),
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function authorizeExecution(string $targetType, int $targetId, array $changes, string $fingerprint): array
    {
        [$current, $proposed, $model, $scope, $forbidden, $wooFields] = $this->prepare($targetType, $targetId, $changes, true);
        $this->context->authorize($scope);
        if ($forbidden !== [] || $wooFields !== []) {
            throw new InvalidArgumentException('preview_conflict');
        }
        $stored = Cache::get($this->previewKey($fingerprint));
        if (! is_array($stored)
            || (int) ($stored['organization_id'] ?? 0) !== $this->organizationId()
            || ! hash_equals((string) ($stored['connection_id'] ?? ''), (string) $this->context->connectionOrFail()->getKey())
            || ($stored['target_type'] ?? null) !== $targetType
            || (int) ($stored['target_id'] ?? 0) !== $targetId
            || ($stored['required_scope'] ?? null) !== $scope
            || ! hash_equals((string) ($stored['current_version'] ?? ''), $this->version($model))
            || ! hash_equals((string) ($stored['current_hash'] ?? ''), hash('sha256', $this->canonical->canonicalJson($current)))
            || ! hash_equals((string) ($stored['proposed_hash'] ?? ''), hash('sha256', $this->canonical->canonicalJson($proposed)))) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }
        Cache::forget($this->previewKey($fingerprint));

        return $proposed;
    }

    /**
     * Shared single-target evaluation used by bulk operations. Applies exactly
     * the same field allow-lists, validator, and Woo ownership rules as the
     * single-record preview, without authorizing or issuing a fingerprint.
     *
     * @param array<string, mixed> $changes
     * @return array{model: Model, current: array<string, mixed>, proposed: array<string, mixed>, changed: list<string>, scope: string, forbidden: list<string>, woo: list<string>, version: string, state_hash: string, proposed_hash: string}
     */
    public function evaluate(string $targetType, int $targetId, array $changes, bool $lock = false): array
    {
        [$current, $proposed, $model, $scope, $forbidden, $wooFields] = $this->prepare($targetType, $targetId, $changes, $lock);
        $changed = array_values(array_filter(array_keys($proposed), fn ($field) => ! $this->same($current[$field] ?? null, $proposed[$field])));

        return [
            'model' => $model,
            'current' => $current,
            'proposed' => $proposed,
            'changed' => $changed,
            'scope' => $scope,
            'forbidden' => $forbidden,
            'woo' => $wooFields,
            'version' => $this->version($model),
            'state_hash' => hash('sha256', $this->canonical->canonicalJson($current)),
            'proposed_hash' => hash('sha256', $this->canonical->canonicalJson($proposed)),
        ];
    }

    /** @param array<string, mixed> $changes @return array{array<string,mixed>,array<string,mixed>,Model,string,list<string>,list<string>} */
    private function prepare(string $targetType, int $targetId, array $changes, bool $lock = false): array
    {
        return match ($targetType) {
            'product' => $this->prepareProduct($targetId, $changes, $lock),
            'variant' => $this->prepareVariant($targetId, $changes, $lock),
            'purchase_price' => $this->preparePurchasePrice($targetId, $changes, $lock),
            'archive_product' => $this->prepareArchive($targetId, $changes, $lock),
            default => throw new InvalidArgumentException('invalid_target_type'),
        };
    }

    /** @param array<string, mixed> $changes */
    private function prepareProduct(int $id, array $changes, bool $lock): array
    {
        $product = $this->product($id, $lock);
        $forbidden = array_values(array_diff(array_keys($changes), self::PRODUCT_FIELDS));
        $current = $this->productCurrent($product);
        $proposed = $this->validator->product(array_replace($current, $this->only($changes, self::PRODUCT_FIELDS)), $this->organizationId(), false, false);

        return [$current, $proposed, $product, 'products.update', $forbidden, $this->wooOwnership->productConflicts($product, array_keys($changes))];
    }

    /** @param array<string, mixed> $changes */
    private function prepareVariant(int $id, array $changes, bool $lock): array
    {
        $variant = $this->variant($id, $lock);
        $forbidden = array_values(array_diff(array_keys($changes), self::VARIANT_FIELDS));
        $current = $this->variantCurrent($variant, includeCost: false);
        $proposed = $this->validator->variant(array_replace($current, $this->only($changes, self::VARIANT_FIELDS)), $this->organizationId(), $variant, false);

        return [$current, $proposed, $variant, 'products.update', $forbidden, $this->wooOwnership->variantConflicts($variant, array_keys($changes))];
    }

    /** @param array<string, mixed> $changes */
    private function preparePurchasePrice(int $id, array $changes, bool $lock): array
    {
        $variant = $this->variant($id, $lock);
        $forbidden = array_values(array_diff(array_keys($changes), ['purchase_price']));
        if (! array_key_exists('purchase_price', $changes)) throw new InvalidArgumentException('purchase_price_required');
        $current = ['purchase_price' => $variant->purchase_price === null ? null : (string) $variant->purchase_price];
        $proposed = ['purchase_price' => Decimal::nonNegative((string) $changes['purchase_price'], 'purchase_price')];

        return [$current, $proposed, $variant, 'product_cost.manage', $forbidden, []];
    }

    /** @param array<string, mixed> $changes */
    private function prepareArchive(int $id, array $changes, bool $lock): array
    {
        $product = $this->product($id, $lock);
        $forbidden = array_keys($changes);
        $current = ['status' => $product->status->value];
        $proposed = ['status' => 'inactive'];

        return [$current, $proposed, $product, 'products.archive', $forbidden, $this->wooOwnership->productConflicts($product, ['status'])];
    }

    private function product(?int $id, bool $lock = false): Product
    {
        $query = Product::query()->where('organization_id', $this->organizationId())->whereKey($id);
        if ($lock) $query->lockForUpdate();
        $product = $query->first();
        if (! $product) throw (new ModelNotFoundException)->setModel(Product::class, [$id]);

        return $product;
    }

    private function variant(?int $id, bool $lock = false): ProductVariant
    {
        $query = ProductVariant::query()->where('organization_id', $this->organizationId())->whereKey($id);
        if ($lock) $query->lockForUpdate();
        $variant = $query->first();
        if (! $variant) throw (new ModelNotFoundException)->setModel(ProductVariant::class, [$id]);

        return $variant;
    }

    /** @return array<string, mixed> */
    private function productCurrent(Product $product): array
    {
        return [
            'name' => $product->name, 'description' => $product->description, 'image_url' => $product->image_url,
            'brand_id' => $product->brand_id, 'category_id' => $product->default_category_id,
            'unit_id' => $product->default_unit_id, 'status' => $product->status->value,
        ];
    }

    /** @return array<string, mixed> */
    private function variantCurrent(ProductVariant $variant, bool $includeCost): array
    {
        $values = [
            'label' => $variant->label, 'sku' => $variant->sku, 'reference' => $variant->reference,
            'barcode' => $variant->barcode, 'image_url' => $variant->image_url,
            'public_price_ttc' => $variant->public_price_ttc, 'unit_price_ht' => $variant->unit_price_ht,
            'regular_sale_price' => $variant->regular_sale_price, 'promotional_sale_price' => $variant->promotional_sale_price,
            'default_sale_price' => $variant->default_sale_price, 'tax_rate_id' => $variant->tax_rate_id,
            'status' => $variant->status->value,
        ];
        if ($includeCost) $values['purchase_price'] = $variant->purchase_price;

        return $values;
    }

    /** @param array<string, mixed> $data @param list<string> $allowed @return array<string, mixed> */
    private function only(array $data, array $allowed): array
    {
        return array_intersect_key($data, array_flip($allowed));
    }

    /** @param array<string, mixed> $data @return list<string> */
    private function forbiddenFields(string $entityType, string $mode, array $data): array
    {
        $allowed = $entityType === 'product' ? self::PRODUCT_FIELDS : ($mode === 'create' ? self::CREATE_VARIANT_FIELDS : self::VARIANT_FIELDS);
        if ($entityType === 'product' && $mode === 'create') $allowed[] = 'variant';
        $forbidden = array_values(array_diff(array_keys($data), $allowed));
        if ($entityType === 'product' && $mode === 'create' && is_array($data['variant'] ?? null)) {
            $forbidden = [...$forbidden, ...array_map(fn ($field) => 'variant.'.$field, array_diff(array_keys($data['variant']), self::CREATE_VARIANT_FIELDS))];
        }

        return $forbidden;
    }

    /** @param list<string> $rules @param list<string>|null $fieldSuffixes @return list<string> */
    private function failedFields(ValidationException $exception, array $rules, ?array $fieldSuffixes = null): array
    {
        return collect($exception->validator->failed())->filter(function ($failedRules, $field) use ($rules, $fieldSuffixes) {
            $hasRule = array_intersect(array_keys($failedRules), $rules) !== [];
            $hasField = $fieldSuffixes === null || collect($fieldSuffixes)->contains(fn ($suffix) => str_ends_with((string) $field, $suffix));

            return $hasRule && $hasField;
        })->keys()->values()->all();
    }

    private function same(mixed $left, mixed $right): bool
    {
        return (string) ($left ?? '') === (string) ($right ?? '');
    }

    private function version(Model $model): string
    {
        return $model->updated_at?->format('Y-m-d\TH:i:s.uP') ?? 'missing';
    }

    private function previewKey(string $fingerprint): string
    {
        if (! preg_match('/\A[0-9a-f]{64}\z/', $fingerprint)) throw new InvalidArgumentException('invalid_preview_fingerprint');

        return 'mcp:product-preview:'.$fingerprint;
    }

    private function organizationId(): int
    {
        return (int) $this->context->organization()->getKey();
    }
}
