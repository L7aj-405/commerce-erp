<?php

namespace App\Actions\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductChannelIdentifier;
use App\Models\ProductImport;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CatalogImport\ExactDecimalParser;
use App\Services\CatalogImport\ExactQuantityParser;
use App\Support\InventoryQuantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PreviewProductImportAction
{
    public function __construct(
        private readonly ExactDecimalParser $prices,
        private readonly ExactQuantityParser $quantities,
    ) {}

    /** @param array<string, int|null> $mapping @param array<string, mixed> $defaults */
    public function execute(User $actor, ProductImport $import, array $mapping, array $defaults): ProductImport
    {
        abort_unless($actor->hasPermission($import->organization_id, 'products.import'), 403);
        foreach (['name', 'sale_price'] as $required) {
            if (! isset($mapping[$required]) || $mapping[$required] === null) {
                throw ValidationException::withMessages(["mapping.{$required}" => 'Ce champ est requis par le catalogue ERP.']);
            }
        }

        $stockMode = $defaults['stock_mode'] ?? 'skip';
        $warehouse = null;
        if ($stockMode === 'import') {
            abort_unless($actor->hasPermission($import->organization_id, 'inventory.opening'), 403);
            if (($mapping['stock_quantity'] ?? null) === null) {
                throw ValidationException::withMessages(['mapping.stock_quantity' => 'Associez une colonne Quantité avant d’importer le stock.']);
            }
            $warehouse = Warehouse::query()->where('organization_id', $import->organization_id)
                ->where('status', 'active')->whereKey($defaults['warehouse_id'] ?? null)->first();
            if (! $warehouse) {
                throw ValidationException::withMessages(['defaults.warehouse_id' => 'Sélectionnez un emplacement actif de cette organisation.']);
            }
        }

        $organization = $import->organization;
        $existingExternalIds = ProductChannelIdentifier::query()->where('organization_id', $organization->id)
            ->where('source', 'woocommerce')->pluck('external_product_id')
            ->mapWithKeys(fn ($value) => [$this->key($value) => true])->all();
        $existingSkus = ProductVariant::query()->where('organization_id', $organization->id)
            ->whereNotNull('sku')->pluck('sku')->mapWithKeys(fn ($value) => [$this->key($value) => true])->all();
        $existingReferences = ProductVariant::query()->where('organization_id', $organization->id)
            ->whereNotNull('reference')->pluck('reference')->mapWithKeys(fn ($value) => [$this->key($value) => true])->all();
        $existingBarcodes = ProductVariant::query()->where('organization_id', $organization->id)
            ->whereNotNull('barcode')->pluck('barcode')->mapWithKeys(fn ($value) => [$this->key($value) => true])->all();
        $brands = Brand::query()->where('organization_id', $organization->id)->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [$this->key($name) => $id])->all();
        $categories = Category::query()->where('organization_id', $organization->id)->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [$this->key($name) => $id])->all();
        $seenExternalIds = [];
        $seenSkus = [];
        $seenReferences = [];
        $seenBarcodes = [];
        $variableParents = [];
        $rows = $import->rows()->get();
        $normalizedRows = $rows->mapWithKeys(fn ($row) => [$row->getKey() => $this->normalize($row->raw_data, $mapping)]);
        $this->inferVariationGroups($normalizedRows);

        foreach ($rows as $row) {
            $normalized = $normalizedRows[$row->getKey()];
            $messages = [];
            $errors = [];
            $errorCode = null;
            $type = $normalized['product_type'];

            if (! in_array($type, ['simple', 'variable', 'variation', 'grouped_variation'], true)) {
                $errors[] = "Type de produit non pris en charge : {$type}.";
            }
            if ($type !== 'variation' && $normalized['name'] === '') {
                $errors[] = 'Le nom du produit est obligatoire.';
                $errorCode ??= 'missing_product_name';
            }
            $sku = $normalized['sku'];
            $reference = $normalized['reference'];
            $externalProductId = $normalized['external_product_id'];

            if ($sku === null && $reference === null && $externalProductId === null) {
                $errors[] = 'Le produit doit avoir un SKU, une référence ou un ID externe.';
                $errorCode ??= 'missing_product_identifier';
            }
            if ($type === 'variation' && $normalized['parent_sku'] === '') {
                $errors[] = 'La variante doit indiquer le SKU de son produit parent.';
                $errorCode ??= 'invalid_variant_relationship';
            }
            if ($normalized['external_product_id'] !== null && (mb_strlen($normalized['external_product_id']) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $normalized['external_product_id']))) {
                $errors[] = 'L’ID externe est invalide.';
            }

            if ($type !== 'variable') {
                $regularPriceValid = true;
                $promotionalPriceValid = true;
                try {
                    $normalized['sale_price'] = $this->prices->parse($normalized['sale_price']);
                } catch (\InvalidArgumentException) {
                    $errors[] = "Le prix \"{$normalized['sale_price']}\" n’est pas valide.";
                    $errorCode ??= 'invalid_regular_price';
                    $regularPriceValid = false;
                }
                try {
                    $normalized['promo_price'] = $normalized['promo_price'] !== '' ? $this->prices->parse($normalized['promo_price']) : null;
                } catch (\InvalidArgumentException) {
                    $errors[] = "Le prix promotionnel \"{$normalized['promo_price']}\" n’est pas valide.";
                    $errorCode ??= 'invalid_promotional_price';
                    $promotionalPriceValid = false;
                }
                if ($regularPriceValid && $promotionalPriceValid) {
                    if ($normalized['promo_price'] !== null && $this->prices->compare($normalized['promo_price'], $normalized['sale_price']) > 0) {
                        $errors[] = 'Le prix promotionnel ne peut pas dépasser le prix régulier.';
                        $errorCode ??= 'invalid_promotional_price';
                    }
                    $normalized['effective_sale_price'] = $normalized['promo_price'] ?? $normalized['sale_price'];
                }
            } else {
                $normalized['sale_price'] = null;
                $normalized['promo_price'] = null;
                $normalized['effective_sale_price'] = null;
                if ($normalized['sku'] !== null) {
                    $variableParents[$this->key($normalized['sku'])] = $row->getKey();
                }
            }

            $duplicate = $this->duplicate($normalized, $existingExternalIds, $existingSkus, $existingReferences, $existingBarcodes, $seenExternalIds, $seenSkus, $seenReferences, $seenBarcodes);
            if ($duplicate) {
                $messages[] = $duplicate.' Cette ligne sera ignorée.';
                $errorCode ??= str_contains($duplicate, 'SKU') ? 'duplicate_sku'
                    : (str_contains($duplicate, 'référence') ? 'duplicate_reference' : 'duplicate_identifier');
            }
            $this->referenceMessage($actor, $organization->id, $normalized['brand'], $brands, 'brands.manage', 'marque', $messages);
            $this->referenceMessage($actor, $organization->id, $normalized['category'], $categories, 'categories.manage', 'catégorie', $messages);

            if ($normalized['stock_quantity'] !== '') {
                if ($stockMode === 'import') {
                    try {
                        $normalized['stock_quantity'] = $this->quantities->parse($normalized['stock_quantity']);
                        $messages[] = InventoryQuantity::compare($normalized['stock_quantity'], InventoryQuantity::ZERO) > 0
                            ? "Stock initial prévu dans {$warehouse->name}."
                            : 'Quantité nulle : aucun mouvement de stock ne sera créé.';
                    } catch (\InvalidArgumentException) {
                        $errors[] = "La quantité \"{$normalized['stock_quantity']}\" n’est pas valide.";
                        $errorCode ??= 'invalid_stock_quantity';
                    }
                } else {
                    $messages[] = 'Stock détecté : il ne sera pas importé selon la configuration choisie.';
                }
            }
            if ($normalized['external_stock_status'] !== '') {
                $normalized['external_stock_status'] = Str::lower($normalized['external_stock_status']);
                if (mb_strlen($normalized['external_stock_status']) > 32) {
                    $errors[] = 'L’état stock WooCommerce est trop long.';
                }
                $messages[] = $normalized['external_product_id'] !== null
                    ? 'État stock WooCommerce conservé comme métadonnée externe uniquement.'
                    : 'État stock WooCommerce non conservé sans ID externe.';
            }
            if ($normalized['image_url'] !== '') {
                $scheme = Str::lower((string) parse_url($normalized['image_url'], PHP_URL_SCHEME));
                if (mb_strlen($normalized['image_url']) > (int) config('catalog_imports.image_url_max_length')
                    || ! filter_var($normalized['image_url'], FILTER_VALIDATE_URL)
                    || ! in_array($scheme, ['http', 'https'], true)) {
                    $messages[] = 'URL d’image invalide : le produit sera importé sans image.';
                    $normalized['image_url'] = null;
                    $normalized['image_url_status'] = 'invalid';
                } else {
                    $normalized['image_url_status'] = 'linked';
                }
            }

            $row->normalized_data = $normalized;
            $row->messages = [...$errors, ...$messages];
            $row->status = $errors !== [] ? 'error' : ($duplicate ? 'skipped' : ($messages !== [] ? 'warning' : 'ready'));
            $row->error_code = $errors !== [] || $duplicate ? ($errorCode ?? 'preview_validation') : null;
            $row->error_phase = $errors !== [] || $duplicate ? 'preview' : null;
            $row->error_message = $errors !== [] ? implode(' ', $errors) : ($duplicate ? $duplicate.'.' : null);
            $row->save();
        }

        $this->validateVariationRelationships($import, $variableParents);
        $this->validateInferredGroups($import);
        $import->mapping = $mapping;
        $import->defaults = [...$defaults, 'stock_mode' => $stockMode];
        $import->global_error_code = null;
        $import->global_error_message = null;
        $import->status = 'previewed';
        $import->ready_count = $import->rows()->where('status', 'ready')->count();
        $import->warning_count = $import->rows()->where('status', 'warning')->count();
        $import->error_count = $import->rows()->where('status', 'error')->count();
        $import->stock_detected = ($mapping['stock_quantity'] ?? null) !== null;
        $import->save();

        return $import->fresh();
    }

    /** @param list<string> $raw @param array<string, int|null> $mapping @return array<string, mixed> */
    private function normalize(array $raw, array $mapping): array
    {
        $value = fn (string $field) => trim((string) ($raw[$mapping[$field] ?? -1] ?? ''));
        $type = Str::lower($value('product_type'));
        $type = match ($type) {
            '', 'simple' => 'simple',
            'variable' => 'variable',
            'variation', 'variant' => 'variation',
            default => $type,
        };

        return [
            'external_product_id' => $this->nullableIdentifier($value('external_product_id')),
            'name' => $value('name'),
            'sku' => $this->nullableIdentifier($value('sku')),
            'sale_price' => $value('sale_price'),
            'promo_price' => $value('promo_price'),
            'effective_sale_price' => null,
            'reference' => $this->nullableIdentifier($value('reference')),
            'barcode' => $value('barcode') ?: null,
            'description' => $value('description') ?: null,
            'category' => $this->firstTaxonomy($value('category')),
            'brand' => $this->firstTaxonomy($value('brand')),
            'product_type' => $type,
            'parent_sku' => $this->nullableIdentifier($value('parent_sku')) ?? '',
            'variant_label' => $value('variant_label') ?: null,
            'group_key' => null,
            'image_url' => $value('image_url'),
            'image_url_status' => $value('image_url') === '' ? 'missing' : 'pending',
            'external_stock_status' => $value('external_stock_status'),
            'stock_quantity' => $value('stock_quantity'),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function inferVariationGroups(Collection $rows): void
    {
        $counts = $rows->filter(fn ($row) => $row['product_type'] === 'simple' && $row['variant_label'] && $row['external_product_id'] !== null)
            ->countBy(fn ($row) => $this->key($row['external_product_id']));
        $groupNames = $rows->filter(fn ($row) => $row['name'] !== '' && $row['external_product_id'] !== null)
            ->mapWithKeys(fn ($row) => [$this->key($row['external_product_id']) => $row['name']]);

        foreach ($rows as $id => $row) {
            if ($row['external_product_id'] === null) {
                continue;
            }
            $externalKey = $this->key($row['external_product_id']);
            if ($row['product_type'] === 'simple' && $row['variant_label'] && ($counts[$externalKey] ?? 0) > 1) {
                $row['product_type'] = 'grouped_variation';
                $row['group_key'] = 'woocommerce:'.$externalKey;
                $row['name'] = $row['name'] ?: ($groupNames[$externalKey] ?? '');
                $rows[$id] = $row;
            }
        }
    }

    /** @param array<string, mixed> $normalized @param array<string, bool> ...$sets */
    private function duplicate(array $normalized, array $existingExternalIds, array $existingSkus, array $existingReferences, array $existingBarcodes, array &$seenExternalIds, array &$seenSkus, array &$seenReferences, array &$seenBarcodes): ?string
    {
        $external = $normalized['external_product_id'] ?: null;
        if ($external) {
            $key = $this->key($external);
            if (isset($existingExternalIds[$key])) {
                return "L’ID WooCommerce \"{$external}\" existe déjà";
            }
            if ($normalized['product_type'] !== 'grouped_variation') {
                if (isset($seenExternalIds[$key])) {
                    return "L’ID WooCommerce \"{$external}\" est dupliqué dans le fichier";
                }
                $seenExternalIds[$key] = true;
            }
        }

        return $this->duplicateValue($normalized['sku'] ?? null, $existingSkus, $seenSkus, 'SKU')
            ?? $this->duplicateValue($normalized['reference'] ?? null, $existingReferences, $seenReferences, 'référence')
            ?? $this->duplicateValue($normalized['barcode'] ?? null, $existingBarcodes, $seenBarcodes, 'code-barres');
    }

    /** @param array<string, bool> $existing @param array<string, bool> $seen */
    private function duplicateValue(mixed $value, array $existing, array &$seen, string $label): ?string
    {
        if (! $value) {
            return null;
        }
        $key = $this->key($value);
        if (isset($existing[$key]) || isset($seen[$key])) {
            return "Le {$label} \"{$value}\" existe déjà";
        }
        $seen[$key] = true;

        return null;
    }

    /** @param array<string, int> $existing @param list<string> $messages */
    private function referenceMessage(User $actor, int $organizationId, ?string $name, array $existing, string $permission, string $label, array &$messages): void
    {
        if (! $name || isset($existing[$this->key($name)])) {
            return;
        }
        $messages[] = $actor->hasPermission($organizationId, $permission)
            ? "La {$label} \"{$name}\" sera créée."
            : "La {$label} \"{$name}\" n’existe pas et ne pourra pas être créée.";
    }

    /** @param array<string, int> $parents */
    private function validateVariationRelationships(ProductImport $import, array $parents): void
    {
        $variationsByParent = [];
        foreach ($import->rows()->whereIn('status', ['ready', 'warning'])->get() as $row) {
            if (($row->normalized_data['product_type'] ?? null) !== 'variation') {
                continue;
            }
            $parent = $this->key($row->normalized_data['parent_sku'] ?? '');
            if (! isset($parents[$parent])) {
                $row->status = 'error';
                $message = 'La variante ne peut pas être associée à son produit parent.';
                $row->messages = [...($row->messages ?? []), $message];
                $row->error_code = 'invalid_variant_relationship';
                $row->error_phase = 'preview';
                $row->error_message = $message;
                $row->save();
            } else {
                $variationsByParent[$parent] = true;
            }
        }
        foreach ($parents as $sku => $rowId) {
            if (! isset($variationsByParent[$sku])) {
                $row = $import->rows()->whereKey($rowId)->firstOrFail();
                $row->status = 'error';
                $message = 'Ce produit variable ne possède aucune variante importable.';
                $row->messages = [...($row->messages ?? []), $message];
                $row->error_code = 'invalid_variant_relationship';
                $row->error_phase = 'preview';
                $row->error_message = $message;
                $row->save();
            }
        }
    }

    private function validateInferredGroups(ProductImport $import): void
    {
        $groups = $import->rows()->get()->filter(fn ($row) => data_get($row->normalized_data, 'product_type') === 'grouped_variation')
            ->groupBy(fn ($row) => data_get($row->normalized_data, 'group_key'));
        foreach ($groups as $rows) {
            $terminal = $rows->contains(fn ($row) => $row->status === 'error') ? 'error'
                : ($rows->contains(fn ($row) => $row->status === 'skipped') ? 'skipped' : null);
            if (! $terminal) {
                continue;
            }
            foreach ($rows as $row) {
                $row->status = $terminal;
                $row->messages = [...($row->messages ?? []), 'Toutes les variantes de ce produit sont traitées ensemble.'];
                if (! $row->error_message) {
                    $row->error_code = $terminal === 'skipped' ? 'group_skipped' : 'group_validation';
                    $row->error_phase = 'preview';
                    $row->error_message = $terminal === 'skipped'
                        ? 'Le produit variable est ignoré avec toutes ses variantes.'
                        : 'Le produit variable contient une variante invalide.';
                }
                $row->save();
            }
        }
    }

    private function firstTaxonomy(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $first = trim(explode(',', $value)[0]);

        return trim(array_slice(array_map('trim', explode('>', $first)), -1)[0]);
    }

    private function key(mixed $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value));
    }

    private function nullableIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
