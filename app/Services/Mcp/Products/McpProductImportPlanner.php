<?php

namespace App\Services\Mcp\Products;

use App\Actions\Catalog\CreateProductAction;
use App\Actions\Catalog\UpdateProductAction;
use App\Actions\Catalog\UpdateVariantAction;
use App\Exceptions\Mcp\McpOperationItemFailure;
use App\Models\McpOperation;
use App\Models\McpOperationItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\CatalogProductDataValidator;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Catalog import/merge rows for the MCP product-operation engine (type
 * product_import). Each row is one variant plus its product fields, in the
 * create_product shape. The variant SKU — mandatory and unique per
 * organization in the canonical catalog — is the merge key:
 *
 * - unknown SKU and no known barcode/reference → create (CreateProductAction);
 * - known SKU → update of that variant and its product, through the same
 *   evaluation and Actions as prepare_product_bulk_update;
 * - a barcode or reference held by another variant, or by a variant with a
 *   different SKU → conflict, never a duplicate and never a re-key.
 *
 * Purchase costs stay out of the import (as in the web catalog import); use
 * prepare_purchase_price_bulk_update. Nothing here mutates the catalog except
 * apply(), which the chunk processor calls inside its item transaction.
 */
final class McpProductImportPlanner
{
    public const TARGET_CREATE = 'import_create';
    public const TARGET_UPDATE = 'import_update';
    public const TARGET_PENDING = 'import';

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly McpProductChangeService $changes,
        private readonly CatalogProductDataValidator $validator,
        private readonly McpIdempotencyService $canonical,
        private readonly CreateProductAction $createProduct,
        private readonly UpdateProductAction $updateProduct,
        private readonly UpdateVariantAction $updateVariant,
    ) {}

    public static function handles(string $targetType): bool
    {
        return in_array($targetType, [self::TARGET_CREATE, self::TARGET_UPDATE], true);
    }

    /**
     * Shape-checks one client row. Row-level problems become an issue instead
     * of rejecting the whole request.
     *
     * @return array{target_type: string, locator: array<string, mixed>, changes: array<string, mixed>, issue: array<string, mixed>|null}
     */
    public function normalize(mixed $row): array
    {
        $locator = $this->locatorOf($row);
        $invalid = fn (string $code, array $extra = []) => ['target_type' => 'invalid', 'locator' => $locator, 'changes' => [], 'issue' => ['code' => $code, ...$extra]];
        if (! is_array($row) || $row === [] || array_is_list($row)) return $invalid('invalid_row');

        $unknown = array_values(array_diff(array_keys($row), [...McpProductChangeService::PRODUCT_FIELDS, 'variant', 'row_number']));
        if ($unknown !== []) return $invalid('unsupported_field', ['fields' => array_slice($unknown, 0, 20)]);
        if (array_key_exists('row_number', $row) && (! is_int($row['row_number']) || $row['row_number'] < 1)) return $invalid('invalid_row', ['fields' => ['row_number']]);

        $variant = $row['variant'] ?? null;
        if (! is_array($variant) || $variant === [] || array_is_list($variant)) return $invalid('invalid_row', ['fields' => ['variant']]);
        if (array_key_exists('purchase_price', $variant)) {
            return $invalid('unsupported_field', ['fields' => ['variant.purchase_price'], 'message' => 'Purchase costs are not part of the product import. Use prepare_purchase_price_bulk_update after the import.']);
        }
        $unknown = array_values(array_diff(array_keys($variant), McpProductChangeService::VARIANT_FIELDS));
        if ($unknown !== []) return $invalid('unsupported_field', ['fields' => array_slice($this->prefixed($unknown), 0, 20)]);

        $sku = $variant['sku'] ?? null;
        if (! is_string($sku) || trim($sku) === '' || mb_strlen(trim($sku)) > 255) return $invalid('missing_identifier', ['fields' => ['variant.sku']]);
        foreach (['reference', 'barcode'] as $field) {
            if (($variant[$field] ?? null) !== null && (! is_string($variant[$field]) || mb_strlen(trim($variant[$field])) > 255)) {
                return $invalid('invalid_row', ['fields' => ['variant.'.$field]]);
            }
        }

        $badDecimals = array_values(array_filter(McpProductOperationService::DECIMAL_FIELDS, fn ($field) => ($variant[$field] ?? null) !== null
            && (! is_string($variant[$field]) || ! preg_match(McpProductOperationService::DECIMAL_PATTERN, $variant[$field]))));
        if ($badDecimals !== []) return $invalid('invalid_decimal', ['fields' => $this->prefixed($badDecimals)]);

        $badImages = array_keys(array_filter(['image_url' => $row['image_url'] ?? null, 'variant.image_url' => $variant['image_url'] ?? null],
            fn ($url) => $url !== null && ! $this->isRemoteUrl($url)));
        if ($badImages !== []) return $invalid('invalid_image_url', ['fields' => $badImages]);

        $changes = $row;
        unset($changes['row_number']);
        $changes['variant']['sku'] = trim($sku);

        return ['target_type' => self::TARGET_PENDING, 'locator' => $locator, 'changes' => $changes, 'issue' => null];
    }

    /**
     * Classifies every shape-valid item without mutating the catalog.
     *
     * @param Collection<int, McpOperationItem> $items
     * @return array{results: array<int, array<string, mixed>>, evaluations: array<int, array<string, mixed>>, scopes: list<string>}
     */
    public function plan(Collection $items): array
    {
        $results = [];
        $evaluations = [];
        $valid = $items->filter(fn (McpOperationItem $item) => $item->target_type === self::TARGET_PENDING)->values();
        $existing = $this->existingIdentifiers($valid);

        // The same SKU in several rows: identical rows collapse onto the first, differing rows all conflict.
        $firsts = [];
        foreach ($valid->groupBy(fn ($item) => $this->key($item->locator['sku'])) as $group) {
            $first = $group->first();
            if ($group->map(fn ($item) => $this->canonical->canonicalJson($item->input_changes ?? []))->unique()->count() > 1) {
                foreach ($group as $item) $results[$item->getKey()] = $this->outcome(McpOperationItem::PREVIEW_CONFLICT, 'conflict', null, [['code' => 'duplicate_identifier_in_request', 'fields' => ['variant.sku']]]);
                continue;
            }
            foreach ($group->slice(1) as $duplicate) {
                $results[$duplicate->getKey()] = $this->outcome(McpOperationItem::PREVIEW_SKIPPED, 'skipped', null, [['code' => 'duplicate_row', 'first_ordinal' => $first->ordinal]]);
            }
            $firsts[] = $first;
        }

        // A barcode or reference claimed by rows with different SKUs.
        foreach (['barcode', 'reference'] as $field) {
            $claims = collect($firsts)->filter(fn ($item) => filled($item->locator[$field] ?? null))->groupBy(fn ($item) => $this->key($item->locator[$field]));
            foreach ($claims->filter(fn ($claim) => $claim->count() > 1) as $claim) {
                foreach ($claim as $item) $results[$item->getKey()] = $this->outcome(McpOperationItem::PREVIEW_CONFLICT, 'conflict', null, [['code' => 'duplicate_identifier_in_request', 'fields' => ['variant.'.$field]]]);
            }
        }

        $updates = [];
        foreach ($firsts as $item) {
            if (isset($results[$item->getKey()])) continue;
            $match = $existing['sku'][$this->key($item->locator['sku'])] ?? null;
            $hits = [
                'variant.barcode' => $existing['barcode'][$this->key($item->locator['barcode'] ?? '')] ?? null,
                'variant.reference' => $existing['reference'][$this->key($item->locator['reference'] ?? '')] ?? null,
            ];
            if ($match === null) {
                $taken = array_keys(array_filter($hits));
                if ($taken !== []) {
                    $results[$item->getKey()] = $this->outcome(McpOperationItem::PREVIEW_CONFLICT, 'conflict', null, [['code' => 'ambiguous_match', 'fields' => $taken]]);
                    continue;
                }
                [$results[$item->getKey()], $evaluations[$item->getKey()]] = $this->planCreate($item);
                continue;
            }
            $foreign = array_keys(array_filter($hits, fn ($hit) => $hit !== null && $hit['variant_id'] !== $match['variant_id']));
            if ($foreign !== []) {
                $results[$item->getKey()] = $this->outcome(McpOperationItem::PREVIEW_CONFLICT, 'conflict', $match['variant_id'], [['code' => 'duplicate_identifier', 'fields' => $foreign]], ['resolved' => $match]);
                continue;
            }
            $updates[$match['product_id']][] = [$item, $match];
        }

        $canUpdate = $this->context->allows('products.update');
        foreach ($updates as $group) {
            // Rows of one product must agree on its product fields; the first row carrying them applies them.
            $parts = collect($group)->map(fn ($entry) => $this->productPart($entry[0]->input_changes ?? []))->filter(fn ($part) => $part !== []);
            $disagree = $parts->map(fn ($part) => $this->canonical->canonicalJson($part))->unique()->count() > 1;
            $carrier = null;
            foreach ($group as [$item, $match]) {
                if ($disagree || ! $canUpdate) {
                    $issue = $disagree ? ['code' => 'conflicting_product_fields'] : ['code' => 'update_not_allowed'];
                    $results[$item->getKey()] = $this->outcome(McpOperationItem::PREVIEW_CONFLICT, 'conflict', $match['variant_id'], [$issue], ['resolved' => $match]);
                    continue;
                }
                $hasProductPart = $this->productPart($item->input_changes ?? []) !== [];
                $withProduct = $hasProductPart && $carrier === null;
                $warnings = $hasProductPart && ! $withProduct ? [['code' => 'product_fields_applied_by_row', 'first_ordinal' => $carrier]] : [];
                if ($withProduct) $carrier = $item->ordinal;
                [$results[$item->getKey()], $evaluations[$item->getKey()]] = $this->planUpdate($item, $match, $withProduct, $warnings);
            }
        }

        $updatesChanged = collect($evaluations)->contains(fn ($evaluation) => $evaluation !== null && $evaluation['target_type'] === self::TARGET_UPDATE);

        return [
            'results' => $results,
            'evaluations' => $evaluations,
            'scopes' => $updatesChanged ? ['products.import', 'products.update'] : ['products.import'],
        ];
    }

    /**
     * Ready rows whose target changed (update) or whose identifiers were taken
     * (create) since the preview.
     */
    public function staleCount(McpOperation $operation): int
    {
        $stale = 0;
        $operation->items()->where('status', McpOperationItem::STATUS_READY)
            ->select(['id', 'target_type', 'target_id', 'locator', 'expected_version', 'preview_result'])
            ->chunkById(1000, function ($chunk) use (&$stale, $operation) {
                $updates = $chunk->where('target_type', self::TARGET_UPDATE);
                $variants = ProductVariant::query()->where('organization_id', $operation->organization_id)
                    ->whereIn('id', $updates->pluck('target_id')->all())->get(['id', 'updated_at'])
                    ->mapWithKeys(fn ($row) => [(int) $row->getKey() => $this->version($row)]);
                $products = Product::query()->where('organization_id', $operation->organization_id)
                    ->whereIn('id', $updates->pluck('preview_result.product_id')->filter()->all())->get(['id', 'updated_at'])
                    ->mapWithKeys(fn ($row) => [(int) $row->getKey() => $this->version($row)]);
                foreach ($updates as $item) {
                    $productVersion = $item->preview_result['expected_product_version'] ?? null;
                    if (($variants[(int) $item->target_id] ?? null) !== $item->expected_version
                        || ($productVersion !== null && ($products[(int) $item->preview_result['product_id']] ?? null) !== $productVersion)) $stale++;
                }

                $creates = $chunk->where('target_type', self::TARGET_CREATE);
                if ($creates->isEmpty()) return;
                $taken = $this->existingIdentifiers($creates, (int) $operation->organization_id);
                foreach ($creates as $item) {
                    foreach (['sku', 'barcode', 'reference'] as $field) {
                        if (filled($item->locator[$field] ?? null) && isset($taken[$field][$this->key($item->locator[$field])])) {
                            $stale++;
                            break;
                        }
                    }
                }
            });

        return $stale;
    }

    /**
     * Applies one pending item inside the processor's item transaction, after
     * re-checking it against the preview under row locks.
     *
     * @return array<string, mixed>
     */
    public function apply(McpOperationItem $item): array
    {
        $actor = $this->context->user();
        $this->context->authorize('products.import');

        if ($item->target_type === self::TARGET_CREATE) {
            try {
                $normalized = $this->validator->product($this->createData($item->input_changes ?? []), $this->organizationId(), true, false);
            } catch (ValidationException $exception) {
                throw new McpOperationItemFailure($this->issueCode($exception) === 'duplicate_identifier' ? 'identifier_taken' : 'stale_target');
            }
            if (! hash_equals((string) $item->proposed_hash, $this->hash($normalized))) throw new McpOperationItemFailure('stale_target');
            $product = $this->createProduct->execute($actor, $this->context->organization(), $normalized);

            return ['action' => 'create', 'product_id' => (int) $product->getKey(), 'variant_id' => (int) $product->variants->firstOrFail()->getKey()];
        }

        $this->context->authorize('products.update');
        $preview = $item->preview_result ?? [];
        $state = $this->inspect($item->input_changes ?? [], (int) ($preview['product_id'] ?? 0), (int) $item->target_id, (bool) ($preview['apply_product_fields'] ?? false), lock: true);
        if ($state['woo'] !== []) throw new McpOperationItemFailure('woocommerce_managed_fields');
        if (! hash_equals((string) $item->expected_version, $state['version'])
            || ! hash_equals((string) $item->expected_state_hash, $state['state_hash'])
            || ! hash_equals((string) $item->proposed_hash, $state['proposed_hash'])) {
            throw new McpOperationItemFailure('stale_target');
        }

        if ($state['product'] !== null && $state['product']['changed'] !== []) {
            $this->updateProduct->execute($actor, $state['product']['model'], $state['product']['proposed']);
        }
        $variant = $state['variant'];
        if ($variant['changed'] !== []) {
            $this->updateVariant->execute($actor, $variant['model'], [...$variant['proposed'], 'purchase_price' => $variant['model']->purchase_price]);
        }

        return ['action' => 'update', 'product_id' => (int) $variant['model']->product_id, 'variant_id' => (int) $variant['model']->getKey(), 'updated_fields' => $state['changed']];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>|null} */
    private function planCreate(McpOperationItem $item): array
    {
        try {
            $normalized = $this->validator->product($this->createData($item->input_changes ?? []), $this->organizationId(), true, false);
        } catch (ValidationException $exception) {
            return [$this->invalid($exception, null), null];
        }
        $variant = $normalized['variant'];
        unset($normalized['variant']);
        $flat = array_filter([...$normalized, ...$this->prefixedKeys($variant)], fn ($value) => $value !== null);
        $normalized['variant'] = $variant;

        return [
            $this->outcome(McpOperationItem::PREVIEW_CHANGED, 'create', null, [], ['changed_fields' => array_keys($flat), 'before' => [], 'target_type' => self::TARGET_CREATE]),
            ['target_type' => self::TARGET_CREATE, 'proposed' => $flat, 'proposed_hash' => $this->hash($normalized), 'version' => null, 'state_hash' => null],
        ];
    }

    /**
     * @param array{variant_id: int, product_id: int} $match @param list<array<string, mixed>> $warnings
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function planUpdate(McpOperationItem $item, array $match, bool $withProduct, array $warnings): array
    {
        $resolved = ['resolved' => $match, 'product_id' => $match['product_id'], 'target_type' => self::TARGET_UPDATE];
        try {
            $state = $this->inspect($item->input_changes ?? [], $match['product_id'], $match['variant_id'], $withProduct, lock: false);
        } catch (ModelNotFoundException) {
            return [$this->outcome(McpOperationItem::PREVIEW_INVALID, 'invalid', null, [['code' => 'target_not_found']]), null];
        } catch (ValidationException $exception) {
            return [$this->invalid($exception, $match['variant_id'], $resolved), null];
        }

        $extra = [...$resolved, 'changed_fields' => $state['changed'], 'before' => $state['before'], 'warnings' => $warnings];
        if ($state['woo'] !== []) {
            return [$this->outcome(McpOperationItem::PREVIEW_CONFLICT, 'conflict', $match['variant_id'], [['code' => 'woocommerce_managed_fields', 'fields' => $state['woo']]], $extra), null];
        }
        if ($state['changed'] === []) {
            return [$this->outcome(McpOperationItem::PREVIEW_UNCHANGED, 'unchanged', $match['variant_id'], [], $extra), null];
        }

        $extra += [
            'apply_product_fields' => $withProduct,
            'expected_product_version' => $state['product'] !== null ? $state['product']['version'] : null,
        ];

        return [
            $this->outcome(McpOperationItem::PREVIEW_CHANGED, 'update', $match['variant_id'], [], $extra),
            ['target_type' => self::TARGET_UPDATE, 'proposed' => $state['proposed'], 'proposed_hash' => $state['proposed_hash'], 'version' => $state['version'], 'state_hash' => $state['state_hash']],
        ];
    }

    /**
     * Evaluates the product and variant parts of an update row with the shared
     * single-target evaluation. The SKU is the merge key and is never changed.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function inspect(array $changes, int $productId, int $variantId, bool $withProduct, bool $lock): array
    {
        $product = $withProduct ? $this->changes->evaluate('product', $productId, $this->productPart($changes), $lock) : null;
        $variantPart = $changes['variant'] ?? [];
        unset($variantPart['sku']);
        $variant = $this->changes->evaluate('variant', $variantId, $variantPart, $lock);

        $productChanged = $product['changed'] ?? [];
        $variantChanged = $this->prefixed($variant['changed']);
        $changed = [...$productChanged, ...$variantChanged];
        $current = [...($product['current'] ?? []), ...$this->prefixedKeys($variant['current'])];
        // Woo ownership only matters for fields the row actually changes.
        $woo = [
            ...array_values(array_intersect($product['woo'] ?? [], $productChanged)),
            ...$this->prefixed(array_values(array_intersect($variant['woo'], $variant['changed']))),
        ];

        return [
            'product' => $product,
            'variant' => $variant,
            'changed' => $changed,
            'before' => array_intersect_key($current, array_flip($changed)),
            'proposed' => [...($product['proposed'] ?? []), ...$this->prefixedKeys($variant['proposed'])],
            'woo' => $woo,
            'version' => $variant['version'],
            'state_hash' => $this->hash([$product['state_hash'] ?? null, $product['version'] ?? null, $variant['state_hash']]),
            'proposed_hash' => $this->hash([$product['proposed_hash'] ?? null, $variant['proposed_hash']]),
        ];
    }

    /**
     * Current variants holding any SKU / barcode / reference of the items, keyed
     * by normalized identifier. Organization-scoped: other tenants never match.
     *
     * @param Collection<int, McpOperationItem> $items
     * @return array<string, array<string, array{variant_id: int, product_id: int}>>
     */
    private function existingIdentifiers(Collection $items, ?int $organizationId = null): array
    {
        $organizationId ??= $this->organizationId();
        $map = ['sku' => [], 'barcode' => [], 'reference' => []];
        foreach (array_keys($map) as $field) {
            $values = $items->map(fn ($item) => $item->locator[$field] ?? null)->filter(fn ($value) => is_string($value) && $value !== '')->unique()->values()->all();
            foreach (array_chunk($values, 1000) as $chunk) {
                ProductVariant::query()->where('organization_id', $organizationId)->whereIn($field, $chunk)
                    ->get(['id', 'product_id', $field])
                    ->each(function (ProductVariant $variant) use (&$map, $field) {
                        $map[$field][$this->key($variant->{$field})] = ['variant_id' => (int) $variant->getKey(), 'product_id' => (int) $variant->product_id];
                    });
            }
        }

        return $map;
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function createData(array $changes): array
    {
        $data = $this->productPart($changes);
        $data['status'] ??= 'active';
        $data['variant'] = $changes['variant'] ?? [];
        $data['variant']['status'] ??= 'active';

        return $data;
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function productPart(array $changes): array
    {
        return array_intersect_key($changes, array_flip(McpProductChangeService::PRODUCT_FIELDS));
    }

    /** @return array<string, mixed> */
    private function locatorOf(mixed $row): array
    {
        if (! is_array($row)) return [];
        $locator = [];
        if (is_int($row['row_number'] ?? null)) $locator['row_number'] = $row['row_number'];
        $variant = is_array($row['variant'] ?? null) ? $row['variant'] : [];
        foreach (['sku', 'barcode', 'reference'] as $field) {
            if (is_string($variant[$field] ?? null) && trim($variant[$field]) !== '') $locator[$field] = mb_substr(trim($variant[$field]), 0, 255);
        }

        return $locator;
    }

    /** Same acceptance as the catalog validator (url:http,https): remote http(s) URLs only, never local paths. */
    private function isRemoteUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function invalid(ValidationException $exception, ?int $targetId, array $extra = []): array
    {
        $errors = collect($exception->errors())->take(20)
            ->map(fn ($messages) => array_values(array_map(fn ($message) => mb_substr((string) $message, 0, 200), array_slice((array) $messages, 0, 3))))
            ->all();
        $status = $this->issueCode($exception) === 'duplicate_identifier' ? McpOperationItem::PREVIEW_CONFLICT : McpOperationItem::PREVIEW_INVALID;

        return $this->outcome($status, $status === McpOperationItem::PREVIEW_CONFLICT ? 'conflict' : 'invalid', $targetId, [['code' => $this->issueCode($exception), 'fields' => $errors]], $extra);
    }

    /** Maps the canonical validator's failed rules onto stable issue codes. */
    private function issueCode(ValidationException $exception): string
    {
        $failed = $exception->validator->failed();
        $has = fn (array $rules, ?string $suffix = null) => collect($failed)->contains(fn ($fieldRules, $field) => array_intersect(array_keys($fieldRules), $rules) !== []
            && ($suffix === null || str_ends_with((string) $field, $suffix)));

        return match (true) {
            $has(['Unique']) => 'duplicate_identifier',
            $has(['Exists']) => 'reference_not_found',
            $has(['Url'], 'image_url') => 'invalid_image_url',
            $has(['Numeric', 'Decimal', 'Min']) => 'invalid_decimal',
            default => 'validation_failed',
        };
    }

    /** @param list<array<string, mixed>> $issues @param array<string, mixed> $extra @return array<string, mixed> */
    private function outcome(string $previewStatus, string $action, ?int $targetId, array $issues, array $extra = []): array
    {
        $targetType = $extra['target_type'] ?? null;
        unset($extra['target_type']);

        return [
            'preview_status' => $previewStatus,
            'target_id' => $targetId,
            'target_type' => $targetType,
            'preview_result' => [
                'action' => $action,
                ...$extra,
                'issues' => array_map(fn ($issue) => [...$issue, 'message' => $issue['message'] ?? McpProductOperationService::message($issue['code'])], $issues),
            ],
        ];
    }

    /** @param list<string> $fields @return list<string> */
    private function prefixed(array $fields): array
    {
        return array_map(fn ($field) => 'variant.'.$field, $fields);
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function prefixedKeys(array $values): array
    {
        return array_combine($this->prefixed(array_keys($values)), array_values($values));
    }

    private function hash(array $value): string
    {
        return hash('sha256', $this->canonical->canonicalJson($value));
    }

    private function version(ProductVariant|Product $model): string
    {
        return $model->updated_at?->format('Y-m-d\TH:i:s.uP') ?? 'missing';
    }

    private function key(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function organizationId(): int
    {
        return (int) $this->context->organization()->getKey();
    }
}
