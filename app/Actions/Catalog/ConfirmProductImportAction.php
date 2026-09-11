<?php

namespace App\Actions\Catalog;

use App\Actions\Inventory\OpeningStockAction;
use App\Enums\CatalogStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductChannelIdentifier;
use App\Models\ProductImport;
use App\Models\ProductImportRow;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\CatalogImport\ProductImportFailureMapper;
use App\Services\CatalogReferenceManager;
use App\Support\InventoryQuantity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ConfirmProductImportAction
{
    public function __construct(
        private readonly CreateProductAction $products,
        private readonly CreateVariantAction $variants,
        private readonly OpeningStockAction $openingStock,
        private readonly ProductImportFailureMapper $failures,
        private readonly CatalogReferenceManager $references,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, ProductImport $import): ProductImport
    {
        abort_unless($actor->hasPermission($import->organization_id, 'products.import'), 403);
        abort_unless($import->status === 'previewed', 409, 'Prévisualisez les données avant de confirmer l’import.');
        $organization = $import->organization;
        $defaults = $import->defaults ?? [];
        $warehouse = $this->warehouse($actor, $organization, $defaults);
        $claimed = ProductImport::query()->whereKey($import->getKey())->where('organization_id', $import->organization_id)
            ->where('status', 'previewed')->update(['status' => 'processing', 'updated_at' => now()]);
        abort_unless($claimed === 1, 409, 'Cet import est déjà en cours ou terminé.');

        try {
            $rows = $import->rows()->get();
            $createdProducts = 0;
            $createdVariants = 0;
            $linkedImageUrls = 0;
            $invalidOrMissingImageUrls = 0;
            $initializedStocks = 0;
            $skipped = $this->initialSkippedCount($rows);
            $failed = $rows->where('status', 'error')->count();
            $brandCache = Brand::query()->where('organization_id', $organization->id)->get()->keyBy(fn ($record) => $this->key($record->name));
            $categoryCache = Category::query()->where('organization_id', $organization->id)->get()->keyBy(fn ($record) => $this->key($record->name));

            foreach ($rows->whereIn('status', ['ready', 'warning'])->filter(fn ($row) => data_get($row->normalized_data, 'product_type') === 'simple') as $row) {
                if ($this->isDuplicate($organization, [$row])) {
                    $this->skip($row, 'Un produit avec cet ID WooCommerce, ce SKU, cette référence ou ce code-barres existe déjà.');
                    $skipped++;

                    continue;
                }
                try {
                    $product = DB::transaction(function () use ($actor, $organization, $row, $defaults, $warehouse, &$brandCache, &$categoryCache, &$initializedStocks) {
                        $product = $this->create($actor, $organization, $row->normalized_data, $defaults, $brandCache, $categoryCache);
                        $variant = $product->variants->firstOrFail();
                        $this->channel($organization, $product, $variant, $row->normalized_data);
                        $this->initializeStock($actor, $organization, $warehouse, $variant, $row, $initializedStocks);
                        $this->markImported($row, $product);

                        return $product;
                    });
                    $createdProducts++;
                    $this->countImageUrl($product, $linkedImageUrls, $invalidOrMissingImageUrls);
                } catch (Throwable $exception) {
                    $this->failFromException($actor, $import, $row, $exception);
                    $failed++;
                }
            }

            $parentRows = $rows->whereIn('status', ['ready', 'warning'])->filter(fn ($row) => data_get($row->normalized_data, 'product_type') === 'variable');
            foreach ($parentRows as $parent) {
                $variations = $rows->whereIn('status', ['ready', 'warning'])->filter(fn ($row) => data_get($row->normalized_data, 'product_type') === 'variation'
                    && $this->key(data_get($row->normalized_data, 'parent_sku')) === $this->key(data_get($parent->normalized_data, 'sku'))
                )->values();
                if ($variations->isEmpty() || $this->isDuplicate($organization, [$parent, ...$variations->all()])) {
                    $this->skipGroup($parent, $variations, 'Ce produit variable existe déjà ou une de ses variantes est en doublon.');
                    $skipped++;

                    continue;
                }
                $imageRow = data_get($parent->normalized_data, 'image_url')
                    ? $parent
                    : ($variations->first(fn ($row) => filled(data_get($row->normalized_data, 'image_url'))) ?? $parent);
                $imageUrl = data_get($imageRow->normalized_data, 'image_url');
                try {
                    $product = DB::transaction(function () use ($actor, $organization, $parent, $variations, $defaults, $warehouse, $imageUrl, &$brandCache, &$categoryCache, &$initializedStocks) {
                        $first = $variations->first();
                        $productData = array_replace($first->normalized_data, [
                            'name' => data_get($parent->normalized_data, 'name'),
                            'description' => data_get($parent->normalized_data, 'description'),
                            'brand' => data_get($parent->normalized_data, 'brand'),
                            'category' => data_get($parent->normalized_data, 'category'),
                            'image_url' => $imageUrl,
                        ]);
                        $product = $this->create($actor, $organization, $productData, $defaults, $brandCache, $categoryCache);
                        $firstVariant = $product->variants->firstOrFail();
                        $this->channel($organization, $product, null, $parent->normalized_data);
                        $this->channel($organization, $product, $firstVariant, $first->normalized_data);
                        $this->initializeStock($actor, $organization, $warehouse, $firstVariant, $first, $initializedStocks);
                        $this->markImported($parent, $product);
                        $this->markImported($first, $product);

                        foreach ($variations->slice(1) as $variation) {
                            $variant = $this->createVariant($actor, $product, $variation->normalized_data, $defaults);
                            $this->channel($organization, $product, $variant, $variation->normalized_data);
                            $this->initializeStock($actor, $organization, $warehouse, $variant, $variation, $initializedStocks);
                            $this->markImported($variation, $product);
                        }

                        return $product;
                    });
                    $createdProducts++;
                    $createdVariants += $variations->count();
                    $this->countImageUrl($product, $linkedImageUrls, $invalidOrMissingImageUrls);
                } catch (Throwable $exception) {
                    $this->failGroupFromException($actor, $import, $parent, $variations, $exception);
                    $failed++;
                }
            }

            $inferredGroups = $rows->whereIn('status', ['ready', 'warning'])
                ->filter(fn ($row) => data_get($row->normalized_data, 'product_type') === 'grouped_variation')
                ->groupBy(fn ($row) => data_get($row->normalized_data, 'group_key'));
            foreach ($inferredGroups as $groupRows) {
                $groupRows = $groupRows->values();
                if ($this->isDuplicate($organization, $groupRows->all())) {
                    $this->skipGroup($groupRows->first(), $groupRows->slice(1), 'Ce produit ou une de ses variantes existe déjà.');
                    $skipped++;

                    continue;
                }
                $imageRow = $groupRows->first(fn ($row) => filled(data_get($row->normalized_data, 'image_url'))) ?? $groupRows->first();
                $imageUrl = data_get($imageRow->normalized_data, 'image_url');
                try {
                    $product = DB::transaction(function () use ($actor, $organization, $groupRows, $defaults, $warehouse, $imageUrl, &$brandCache, &$categoryCache, &$initializedStocks) {
                        $first = $groupRows->first();
                        $productData = array_replace($first->normalized_data, ['image_url' => $imageUrl]);
                        $product = $this->create($actor, $organization, $productData, $defaults, $brandCache, $categoryCache);
                        $firstVariant = $product->variants->firstOrFail();
                        $this->channel($organization, $product, null, $first->normalized_data);
                        $this->initializeStock($actor, $organization, $warehouse, $firstVariant, $first, $initializedStocks);
                        $this->markImported($first, $product);

                        foreach ($groupRows->slice(1) as $row) {
                            $variant = $this->createVariant($actor, $product, $row->normalized_data, $defaults);
                            $this->initializeStock($actor, $organization, $warehouse, $variant, $row, $initializedStocks);
                            $this->markImported($row, $product);
                        }

                        return $product;
                    });
                    $createdProducts++;
                    $createdVariants += $groupRows->count();
                    $this->countImageUrl($product, $linkedImageUrls, $invalidOrMissingImageUrls);
                } catch (Throwable $exception) {
                    $this->failGroupFromException($actor, $import, $groupRows->first(), $groupRows->slice(1), $exception);
                    $failed++;
                }
            }

            $initializedStocks = InventoryMovement::query()->where('organization_id', $organization->id)
                ->where('reference', 'like', "IMPORT-{$import->id}-ROW-%")->count();
            $import->status = $failed > 0
                ? ($createdProducts > 0 ? 'completed_with_errors' : 'failed')
                : 'completed';
            $import->imported_count = $createdProducts;
            $import->created_product_count = $createdProducts;
            $import->created_variant_count = $createdVariants;
            $import->linked_image_url_count = $linkedImageUrls;
            $import->invalid_or_missing_image_url_count = $invalidOrMissingImageUrls;
            $import->initialized_stock_count = $initializedStocks;
            $import->skipped_count = $skipped;
            $import->failed_count = $failed;
            $import->global_error_code = $import->status === 'failed' ? 'no_rows_imported' : null;
            $import->global_error_message = $import->status === 'failed'
                ? 'Aucun produit n’a pu être importé. Consultez les erreurs par ligne ci-dessous.'
                : null;
            $import->completed_at = now();
            $import->expires_at = now()->addDays((int) config('catalog_imports.completed_retention_days'));
            $import->save();

            $this->audit->record('catalog.products_imported', $actor, $organization, auditable: $import, newValues: [
                'file_name' => $import->original_file_name,
                'created_product_count' => $createdProducts,
                'created_variant_count' => $createdVariants,
                'linked_image_url_count' => $linkedImageUrls,
                'invalid_or_missing_image_url_count' => $invalidOrMissingImageUrls,
                'initialized_stock_count' => $initializedStocks,
                'skipped_count' => $skipped,
                'failed_count' => $failed,
            ]);

            return $import->fresh();
        } catch (Throwable $exception) {
            return $this->failGlobally($actor, $import, $exception);
        }
    }

    /** @param array<string, mixed> $defaults */
    private function warehouse(User $actor, Organization $organization, array $defaults): ?Warehouse
    {
        if (($defaults['stock_mode'] ?? 'skip') !== 'import') {
            return null;
        }
        abort_unless($actor->hasPermission($organization, 'inventory.opening'), 403);

        $warehouse = Warehouse::query()->where('organization_id', $organization->id)->where('status', 'active')
            ->whereKey($defaults['warehouse_id'] ?? null)->first();
        if (! $warehouse) {
            throw ValidationException::withMessages([
                'defaults.warehouse_id' => 'L’emplacement sélectionné n’est pas disponible pour cette organisation.',
            ]);
        }

        return $warehouse;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $defaults */
    private function create(User $actor, Organization $organization, array $data, array $defaults, &$brandCache, &$categoryCache): Product
    {
        $brandId = $this->reference($actor, $organization, Brand::class, $data['brand'] ?? null, 'brands.manage', $brandCache)
            ?? ($defaults['brand_id'] ?? null);
        $categoryId = $this->reference($actor, $organization, Category::class, $data['category'] ?? null, 'categories.manage', $categoryCache)
            ?? ($defaults['category_id'] ?? null);

        return $this->products->execute($actor, $organization, [
            'name' => $data['name'], 'description' => $data['description'], 'image_url' => isset($data['image_url']) && trim((string) $data['image_url']) !== ''
    ? trim((string) $data['image_url'])
    : null, 'brand_id' => $brandId,
            'category_id' => $categoryId, 'unit_id' => $defaults['unit_id'] ?? null, 'status' => CatalogStatus::Active->value,
            'variant' => $this->variantData($data, $defaults),
        ]);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $defaults */
    private function createVariant(User $actor, Product $product, array $data, array $defaults): ProductVariant
    {
        return $this->variants->execute($actor, $product, $this->variantData($data, $defaults));
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $defaults @return array<string, mixed> */
    private function variantData(array $data, array $defaults): array
    {
        return [
            'label' => $data['variant_label'], 'sku' => $this->nullableIdentifier($data['sku'] ?? null), 'reference' => $this->nullableIdentifier($data['reference'] ?? null),
            'barcode' => $this->nullableIdentifier($data['barcode'] ?? null), 'purchase_price' => null, 'regular_sale_price' => $data['sale_price'],
            'promotional_sale_price' => $data['promo_price'], 'default_sale_price' => $data['effective_sale_price'],
            // The imported price is the customer-facing (public / TTC) price. No explicit
            // HT is supplied; it is derived later from the effective tax rate.
            'public_price_ttc' => $data['effective_sale_price'], 'unit_price_ht' => null,
            'tax_rate_id' => $defaults['tax_rate_id'] ?? null, 'status' => CatalogStatus::Active->value,
        ];
    }

    /** @param array<string, mixed> $data */
    private function channel(Organization $organization, Product $product, ?ProductVariant $variant, array $data): void
    {
        $externalProductId = $this->nullableIdentifier($data['external_product_id'] ?? null);

        if ($externalProductId === null) {
            return;
        }
        $identifier = new ProductChannelIdentifier;
        $identifier->organization_id = $organization->id;
        $identifier->product_id = $product->id;
        $identifier->product_variant_id = $variant?->id;
        $identifier->source = 'woocommerce';
        $identifier->external_product_id = $externalProductId;
        $identifier->external_stock_status = ($data['external_stock_status'] ?? '') ?: null;
        $identifier->save();
    }

    private function initializeStock(User $actor, Organization $organization, ?Warehouse $warehouse, ProductVariant $variant, ProductImportRow $row, int &$count): void
    {
        $quantity = data_get($row->normalized_data, 'stock_quantity');
        if (! $warehouse || $quantity === null || $quantity === '' || InventoryQuantity::compare($quantity, InventoryQuantity::ZERO) === 0) {
            return;
        }
        $this->openingStock->execute($actor, $organization, $warehouse, $variant, $quantity, 'Stock initial importé depuis le catalogue WooCommerce', "IMPORT-{$row->product_import_id}-ROW-{$row->row_number}");
        $count++;
    }

    private function countImageUrl(Product $product, int &$linked, int &$invalidOrMissing): void
    {
        if ($product->image_url) {
            $linked++;

            return;
        }

        $invalidOrMissing++;
    }

    private function reference(User $actor, Organization $organization, string $class, ?string $name, string $permission, &$cache): ?int
    {
        if (! $name) {
            return null;
        }
        $key = $this->key($name);
        if ($cache->has($key)) {
            return $cache->get($key)->getKey();
        }
        if (! $actor->hasPermission($organization, $permission)) {
            return null;
        }

        $record = new $class;
        $slug = Str::slug($name) ?: 'reference';
        $base = $slug;
        $suffix = 2;
        while ($class::query()->where('organization_id', $organization->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }
        $record = $this->references->save($actor, $organization, $record, [
            'name' => trim(preg_replace('/\s+/', ' ', $name) ?? $name), 'slug' => $slug,
            'parent_id' => null, 'status' => CatalogStatus::Active->value,
        ], $class === Brand::class ? 'brand.created' : 'category.created');
        $cache->put($key, $record);

        return $record->getKey();
    }

    /** @param list<ProductImportRow> $rows */
    private function isDuplicate(Organization $organization, array $rows): bool
    {
        $externalIds = collect($rows)->map(fn ($row) => $this->nullableIdentifier(data_get($row->normalized_data, 'external_product_id')))
            ->filter()->unique()->values();
        if ($externalIds->isNotEmpty() && ProductChannelIdentifier::query()->where('organization_id', $organization->id)
            ->where('source', 'woocommerce')->whereIn('external_product_id', $externalIds)->exists()) {
            return true;
        }
        foreach ($rows as $row) {
            $data = $row->normalized_data;
            $sku = $this->nullableIdentifier($data['sku'] ?? null);
            $reference = $this->nullableIdentifier($data['reference'] ?? null);
            $barcode = $this->nullableIdentifier($data['barcode'] ?? null);

            if ($sku === null && $reference === null && $barcode === null) {
                continue;
            }

            if (ProductVariant::query()->where('organization_id', $organization->id)->where(function ($query) use ($sku, $reference, $barcode) {
                if ($sku !== null) {
                    $query->where('sku', $sku);
                }
                if ($reference !== null) {
                    $query->orWhere('reference', $reference);
                }
                if ($barcode !== null) {
                    $query->orWhere('barcode', $barcode);
                }
            })->exists()) {
                return true;
            }
        }

        return false;
    }

    private function initialSkippedCount(Collection $rows): int
    {
        $skipped = $rows->where('status', 'skipped');

        return $skipped->filter(fn ($row) => in_array(data_get($row->normalized_data, 'product_type'), ['simple', 'variable'], true))->count()
            + $skipped->filter(fn ($row) => data_get($row->normalized_data, 'product_type') === 'grouped_variation')
                ->pluck('normalized_data.group_key')->unique()->count();
    }

    private function markImported(ProductImportRow $row, Product $product): void
    {
        $row->status = 'imported';
        $row->product_id = $product->getKey();
        $row->save();
    }

    private function skip(ProductImportRow $row, string $message, string $code = 'duplicate_identifier'): void
    {
        $row->status = 'skipped';
        $row->messages = [...($row->messages ?? []), $message];
        $row->error_code = $code;
        $row->error_phase ??= 'runtime';
        $row->error_message = $message;
        $row->save();
    }

    private function fail(ProductImportRow $row, string $code, string $message): void
    {
        $row->status = 'error';
        $row->messages = [...($row->messages ?? []), $message];
        $row->error_code = $code;
        $row->error_phase = 'runtime';
        $row->error_message = $message;
        $row->save();
    }

    private function skipGroup(ProductImportRow $parent, Collection $children, string $message): void
    {
        $this->skip($parent, $message);
        foreach ($children as $child) {
            $this->skip($child, 'Variante ignorée avec son produit parent.');
        }
    }

    private function failGroup(ProductImportRow $parent, Collection $children, string $code, string $message): void
    {
        $this->fail($parent, $code, $message);
        foreach ($children as $child) {
            $this->fail($child, $code, 'Variante non importée avec son produit parent. '.$message);
        }
    }

    private function failFromException(User $actor, ProductImport $import, ProductImportRow $row, Throwable $exception): void
    {
        $failure = $this->failures->map($exception, $row);
        $this->fail($row, $failure['code'], $failure['message']);
        $this->logUnexpected($actor, $import, $row, $exception, $failure['expected']);
    }

    private function failGroupFromException(User $actor, ProductImport $import, ProductImportRow $parent, Collection $children, Throwable $exception): void
    {
        $failure = $this->failures->map($exception, $parent);
        $this->failGroup($parent, $children, $failure['code'], $failure['message']);
        $this->logUnexpected($actor, $import, $parent, $exception, $failure['expected']);
    }

    private function logUnexpected(User $actor, ProductImport $import, ProductImportRow $row, Throwable $exception, bool $expected): void
    {
        if ($expected) {
            return;
        }

        Log::error('product_import.row_failed', [
            'import_id' => $import->getKey(),
            'row_number' => $row->row_number,
            'organization_id' => $import->organization_id,
            'user_id' => $actor->getKey(),
            'source_filename' => $import->original_file_name,
            'product_name' => data_get($row->normalized_data, 'name'),
            'sku' => data_get($row->normalized_data, 'sku'),
            'exception_class' => $exception::class,
            'exception_message' => $this->safeExceptionMessage($exception),
        ]);
    }

    private function failGlobally(User $actor, ProductImport $import, Throwable $exception): ProductImport
    {
        Log::error('product_import.failed', [
            'import_id' => $import->getKey(),
            'organization_id' => $import->organization_id,
            'user_id' => $actor->getKey(),
            'source_filename' => $import->original_file_name,
            'exception_class' => $exception::class,
            'exception_message' => $this->safeExceptionMessage($exception),
        ]);

        $importedProducts = $import->rows()->whereNotNull('product_id')->distinct()->count('product_id');
        $import->status = $importedProducts > 0 ? 'completed_with_errors' : 'failed';
        $import->imported_count = $importedProducts;
        $import->created_product_count = $importedProducts;
        $import->failed_count = max(1, $import->rows()->where('status', 'error')->count());
        $import->global_error_code = 'technical_error';
        $import->global_error_message = 'Une erreur technique a interrompu l’import. Aucun détail technique sensible n’est affiché.';
        $import->completed_at = now();
        $import->expires_at = now()->addDays((int) config('catalog_imports.completed_retention_days'));
        $import->save();

        return $import->fresh();
    }

    private function safeExceptionMessage(Throwable $exception): string
    {
        $message = $exception instanceof QueryException
            ? ($exception->getPrevious()?->getMessage() ?? 'Database query failed.')
            : $exception->getMessage();
        $message = preg_replace('/\s+/', ' ', $message) ?? 'Technical import failure.';
        $message = preg_replace('/(?i)(password|token|secret|api[_-]?key)\s*[=:]\s*[^,;\s]+/', '$1=[redacted]', $message) ?? $message;
        $message = preg_replace('#(https?://)[^/@\s]+@#i', '$1[redacted]@', $message) ?? $message;

        return Str::limit($message, 2000, '');
    }

    private function key(mixed $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value));
    }

    private function nullableIdentifier(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
