<?php

namespace App\Actions\WooCommerce;

use App\Actions\Catalog\CreateProductAction;
use App\Actions\Catalog\CreateVariantAction;
use App\Actions\Inventory\AdjustInventoryAction;
use App\Actions\Inventory\OpeningStockAction;
use App\Enums\InventoryMovementType;
use App\Models\Brand;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductChannelIdentifier;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WooCommerceIntegration;
use App\Models\WooCommerceSyncRun;
use App\Services\AuditLogger;
use App\Services\WooCommerce\NormalizedWooProduct;
use App\Services\WooCommerce\NormalizedWooVariant;
use App\Services\WooCommerce\WooCategorySynchronizer;
use App\Services\WooCommerce\WooCommerceApiException;
use App\Services\WooCommerce\WooCommerceClient;
use App\Services\WooCommerce\WooCommerceProductNormalizer;
use App\Services\WooCommerce\WooTaxClassResolver;
use App\Support\Decimal;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Orchestrates a WooCommerce -> ERP product synchronization for one integration.
 *
 * WooCommerce is the authoritative direction. The run is idempotent: it upserts
 * Products / Variants keyed by integration-scoped remote mappings, so re-running
 * never duplicates. Each product is processed in its own small transaction — one
 * bad product is recorded and skipped, the rest continue.
 */
class SyncWooCommerceProductsAction
{
    private ?WooCommerceClient $clientOverride = null;

    public function __construct(
        private readonly WooCommerceProductNormalizer $normalizer,
        private readonly WooTaxClassResolver $taxResolver,
        private readonly CreateProductAction $createProduct,
        private readonly CreateVariantAction $createVariant,
        private readonly AdjustInventoryAction $adjustInventory,
        private readonly OpeningStockAction $openingStock,
        private readonly AuditLogger $audit,
    ) {}

    /** Test seam: inject a fake client. */
    public function usingClient(WooCommerceClient $client): self
    {
        $this->clientOverride = $client;

        return $this;
    }

    public function execute(WooCommerceIntegration $integration, User $actor, string $mode = 'full'): WooCommerceSyncRun
    {
        $lock = Cache::lock('woocommerce-sync:'.$integration->getKey(), 3600);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['sync' => 'Une synchronisation est déjà en cours pour cette intégration.']);
        }

        $organization = $integration->organization;
        $store = $integration->defaultStore
            ?? $organization->stores()->where('status', 'active')->orderBy('id')->first();
        $client = $this->clientOverride ?? WooCommerceClient::for($integration);

        $run = new WooCommerceSyncRun;
        $run->organization_id = $organization->getKey();
        $run->woocommerce_integration_id = $integration->getKey();
        $run->type = 'products';
        $run->mode = $mode;
        $run->status = WooCommerceSyncRun::STATUS_RUNNING;
        $run->started_at = now();
        $run->triggered_by_user_id = $actor->getKey();
        $run->errors = [];
        $run->save();

        $integration->forceFill(['last_product_sync_started_at' => now()])->save();
        $this->audit->record('woocommerce.product_sync_started', $actor, $organization, auditable: $integration, newValues: [
            'run_id' => $run->getKey(), 'mode' => $mode, 'sync_stock' => $integration->sync_stock,
        ]);

        try {
            $priceMeaning = $this->resolvePriceMeaning($integration, $client);

            $categories = new WooCategorySynchronizer($integration);
            $categories->prime($this->safe(fn () => $client->getAllProductCategories()) ?? []);

            $query = [
                'per_page' => (int) config('woocommerce.per_page', 50),
                'orderby' => 'id',
                'order' => 'asc',
            ];
            if ($mode === 'incremental' && $integration->last_product_modified_cursor) {
                $query['modified_after'] = $integration->last_product_modified_cursor;
            }

            $page = 1;
            $maxPages = (int) config('woocommerce.max_pages', 2000);
            $newestModified = $integration->last_product_modified_cursor;

            do {
                $result = $client->getProducts($query + ['page' => $page]);
                $totalPages = max(1, $result['total_pages']);

                foreach ($result['items'] as $raw) {
                    $run->increment('products_read');
                    $remoteId = $raw['id'] ?? null;

                    try {
                        $variations = ($raw['type'] ?? 'simple') === 'variable'
                            ? $this->fetchVariations($client, (int) $remoteId)
                            : [];
                        $normalized = $this->normalizer->normalize($raw, $variations, $integration);

                        DB::transaction(fn () => $this->syncProduct(
                            $normalized, $integration, $organization, $store, $actor, $run, $categories, $priceMeaning,
                        ), 1);

                        if ($normalized->dateModified && (! $newestModified || $normalized->dateModified > $newestModified)) {
                            $newestModified = $normalized->dateModified;
                        }
                    } catch (WooCommerceApiException $exception) {
                        throw $exception; // an API failure aborts the whole run
                    } catch (Throwable $exception) {
                        $run->increment('products_failed');
                        $this->recordIssue($run, $remoteId, $this->safeMessage($exception));
                    }
                }

                $page++;
            } while ($page <= $totalPages && $page <= $maxPages);

            $run->categories_synced = count($categories->resolvedMap());
            $run->status = $run->products_failed > 0
                ? WooCommerceSyncRun::STATUS_COMPLETED_WITH_ERRORS
                : WooCommerceSyncRun::STATUS_COMPLETED;
            $run->message = "Prix WooCommerce interprétés comme {$priceMeaning['label']}"
                .($priceMeaning['detected'] ? ' (détecté depuis les réglages de la boutique).' : ' (réglage non détecté — valeur par défaut).');
            $run->completed_at = now();
            $run->save();

            $integration->forceFill([
                'last_product_sync_completed_at' => now(),
                'last_product_modified_cursor' => $newestModified,
                'synced_product_count' => $this->mappedProductCount($integration),
            ])->save();
        } catch (WooCommerceApiException $exception) {
            $this->failRun($run, $exception->userMessage());
        } catch (Throwable $exception) {
            report($exception);
            $this->failRun($run, 'Une erreur inattendue a interrompu la synchronisation.');
        } finally {
            $lock->release();
        }

        $this->audit->record(
            $run->status === WooCommerceSyncRun::STATUS_FAILED ? 'woocommerce.product_sync_failed' : 'woocommerce.product_sync_completed',
            $actor, $organization, auditable: $integration,
            newValues: $run->only([
                'id', 'status', 'products_read', 'products_created', 'products_updated',
                'products_skipped', 'products_failed', 'variants_synced', 'categories_synced', 'stock_adjustments',
            ]),
        );

        return $run->fresh();
    }

    /* -------------------------------------------------------------------------
     |  Per-product sync (runs inside its own transaction)
     * ---------------------------------------------------------------------- */

    private function syncProduct(
        NormalizedWooProduct $woo,
        WooCommerceIntegration $integration,
        Organization $organization,
        ?Store $store,
        User $actor,
        WooCommerceSyncRun $run,
        WooCategorySynchronizer $categories,
        array $priceMeaning,
    ): void {
        $product = $this->resolveProduct($woo, $integration, $organization);
        $creating = $product === null;

        $categoryId = null;
        foreach ($woo->categories as $wooCategory) {
            $categoryId = $categories->map($wooCategory)->getKey();
            break; // the first Woo category becomes the ERP default category
        }

        $brandId = $woo->brandName ? $this->resolveBrand($organization, $woo->brandName) : null;

        if ($creating) {
            $firstVariant = $woo->variants[0];
            $tax = $this->taxResolver->resolve($woo->taxStatus, $firstVariant->taxClass ?? $woo->taxClass, $organization, $store);
            if ($tax['warning']) {
                $this->recordIssue($run, $woo->remoteId, $tax['warning']);
            }

            $product = $this->createProduct->execute($actor, $organization, [
                'name' => $woo->name,
                'description' => $woo->description,
                'image_url' => $woo->imageUrl,
                'brand_id' => $brandId,
                'category_id' => $categoryId,
                'unit_id' => null,
                'status' => $woo->erpStatus(),
                'variant' => $this->variantPayload($firstVariant, $woo, $tax['tax_rate_id'], $priceMeaning, $organization),
            ]);
            $run->increment('products_created');

            $variants = collect($product->variants->all());
            $this->linkChannel($integration, $product, $variants->first(), $woo, $woo->isVariable() ? $firstVariant : null);
            $run->increment('variants_synced');

            foreach (array_slice($woo->variants, 1) as $wooVariant) {
                $variantTax = $this->taxResolver->resolve($woo->taxStatus, $wooVariant->taxClass ?? $woo->taxClass, $organization, $store);
                if ($variantTax['warning']) {
                    $this->recordIssue($run, $woo->remoteId, $variantTax['warning']);
                }
                $erpVariant = $this->createVariant->execute($actor, $product, $this->variantPayload($wooVariant, $woo, $variantTax['tax_rate_id'], $priceMeaning, $organization));
                $this->linkChannel($integration, $product, $erpVariant, $woo, $wooVariant);
                $run->increment('variants_synced');
            }
        } else {
            $product->forceFill([
                'name' => $woo->name,
                'description' => $woo->description,
                'image_url' => $woo->imageUrl,
                'brand_id' => $brandId ?? $product->brand_id,
                'default_category_id' => $categoryId ?? $product->default_category_id,
                'status' => $woo->erpStatus(),
            ])->save();
            $run->increment('products_updated');

            $seenVariantIds = [];
            foreach ($woo->variants as $wooVariant) {
                $tax = $this->taxResolver->resolve($woo->taxStatus, $wooVariant->taxClass ?? $woo->taxClass, $organization, $store);
                if ($tax['warning']) {
                    $this->recordIssue($run, $woo->remoteId, $tax['warning']);
                }

                $erpVariant = $this->resolveVariant($integration, $product, $woo, $wooVariant);
                if ($erpVariant) {
                    $this->applyVariantValues($erpVariant, $wooVariant, $woo, $tax['tax_rate_id'], $priceMeaning);
                    $erpVariant->save();
                } else {
                    $erpVariant = $this->createVariant->execute($actor, $product, $this->variantPayload($wooVariant, $woo, $tax['tax_rate_id'], $priceMeaning, $organization));
                }
                $this->linkChannel($integration, $product, $erpVariant, $woo, $woo->isVariable() ? $wooVariant : null);
                $seenVariantIds[] = $erpVariant->getKey();
                $run->increment('variants_synced');
            }

            // Woo variations that vanished: deactivate (never hard-delete — historical usage).
            $product->variants()
                ->whereNotIn('id', $seenVariantIds)
                ->whereHas('channelIdentifiers', fn ($q) => $q
                    ->where('woocommerce_integration_id', $integration->getKey())
                    ->whereNotNull('remote_variation_id'))
                ->where('status', 'active')
                ->update(['status' => 'inactive']);
        }

        if ($this->stockSyncEnabled($integration, $actor)) {
            $product->loadMissing('variants.channelIdentifiers');
            foreach ($woo->variants as $index => $wooVariant) {
                $erpVariant = $this->resolveVariant($integration, $product->fresh('variants'), $woo, $wooVariant)
                    ?? $product->fresh('variants')->variants[$index] ?? null;
                if ($erpVariant) {
                    $this->reconcileStock($integration, $organization, $actor, $erpVariant, $wooVariant, $run);
                }
            }
        }
    }

    /* -------------------------------------------------------------------------
     |  Identity resolution
     * ---------------------------------------------------------------------- */

    private function resolveProduct(NormalizedWooProduct $woo, WooCommerceIntegration $integration, Organization $organization): ?Product
    {
        $mapping = ProductChannelIdentifier::query()
            ->where('organization_id', $organization->getKey())
            ->where('source', WooCommerceIntegration::CHANNEL)
            ->where('external_product_id', (string) $woo->remoteId)
            ->whereNull('remote_variation_id')
            // Adopt a legacy (CSV-import) mapping with no integration on the first API sync.
            ->where(fn ($q) => $q->where('woocommerce_integration_id', $integration->getKey())->orWhereNull('woocommerce_integration_id'))
            ->first();

        if ($mapping?->product) {
            return $mapping->product;
        }

        // Fall back to a valid, non-empty SKU match. NULL SKU is never an identity.
        if ($woo->sku !== null) {
            $variant = ProductVariant::query()
                ->where('organization_id', $organization->getKey())
                ->where('sku', $woo->sku)
                ->with('product')
                ->first();

            return $variant?->product;
        }

        return null;
    }

    private function resolveVariant(WooCommerceIntegration $integration, Product $product, NormalizedWooProduct $woo, NormalizedWooVariant $wooVariant): ?ProductVariant
    {
        $remoteEntityId = $wooVariant->remoteVariationId ?? $woo->remoteId;

        $mapping = ProductChannelIdentifier::query()
            ->where('organization_id', $product->organization_id)
            ->where('source', WooCommerceIntegration::CHANNEL)
            ->where('external_product_id', (string) $remoteEntityId)
            ->whereNotNull('product_variant_id')
            ->where(fn ($q) => $q->where('woocommerce_integration_id', $integration->getKey())->orWhereNull('woocommerce_integration_id'))
            ->first();

        if ($mapping?->productVariant && (int) $mapping->productVariant->product_id === (int) $product->getKey()) {
            return $mapping->productVariant;
        }

        if ($wooVariant->sku !== null) {
            return $product->variants()->where('sku', $wooVariant->sku)->first();
        }

        // Simple product with no mapping yet: the single existing variant.
        if (! $woo->isVariable() && $product->variants()->count() === 1) {
            return $product->variants()->first();
        }

        return null;
    }

    /* -------------------------------------------------------------------------
     |  Payload / value builders
     * ---------------------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function variantPayload(NormalizedWooVariant $v, NormalizedWooProduct $woo, ?int $taxRateId, array $priceMeaning, Organization $organization): array
    {
        $effective = $v->effectivePrice ?? $v->regularPrice ?? '0.0000';
        $regular = $v->regularPrice ?? $effective;
        $promo = $v->salePrice && Decimal::compare($v->salePrice, $regular) < 0 ? $v->salePrice : null;

        $payload = [
            'label' => $woo->isVariable() ? ($v->label() ?? ($v->sku ?? null)) : null,
            'sku' => $v->sku,
            'reference' => $v->reference,
            'barcode' => null,
            // Variation's own Woo image, already falling back to the parent's main
            // image inside the normalizer when the variation has none (see
            // WooCommerceProductNormalizer::normalizeVariation) — never the parent
            // image unconditionally.
            'image_url' => $v->imageUrl,
            'purchase_price' => null,
            'regular_sale_price' => $regular,
            'promotional_sale_price' => $promo,
            'tax_rate_id' => $taxRateId,
            'status' => $v->status === 'publish' ? 'active' : ($woo->erpStatus()),
        ];

        if ($priceMeaning['includes_tax']) {
            $payload['public_price_ttc'] = $effective;
        } else {
            $payload['unit_price_ht'] = $effective;
            $payload['default_sale_price'] = $effective;
        }

        return $payload;
    }

    private function applyVariantValues(ProductVariant $variant, NormalizedWooVariant $v, NormalizedWooProduct $woo, ?int $taxRateId, array $priceMeaning): void
    {
        $effective = $v->effectivePrice ?? $v->regularPrice ?? $variant->default_sale_price ?? '0.0000';
        $regular = $v->regularPrice ?? $effective;
        $promo = $v->salePrice && Decimal::compare($v->salePrice, $regular) < 0 ? $v->salePrice : null;

        $variant->regular_sale_price = $regular;
        $variant->promotional_sale_price = $promo;
        $variant->tax_rate_id = $taxRateId;
        // Re-applied on every sync so a changed or removed Woo variation image is
        // reflected here too — never left stale (see class doc on
        // WooCommerceProductNormalizer::normalizeVariation for the fallback rule).
        $variant->image_url = $v->imageUrl;
        if ($woo->isVariable()) {
            $variant->label = $v->label() ?? $variant->label;
        }
        $variant->status = $v->status === 'publish' ? 'active' : $woo->erpStatus();

        if ($priceMeaning['includes_tax']) {
            $variant->public_price_ttc = $effective;
            $variant->unit_price_ht = null;
            $variant->default_sale_price = $promo ?? $regular;
        } else {
            $variant->unit_price_ht = $effective;
            $variant->public_price_ttc = null;
            $variant->default_sale_price = $effective;
        }

        // Keep an explicit Woo SKU in sync; never fabricate one.
        if ($v->sku !== null && $variant->sku !== $v->sku
            && ! ProductVariant::query()->where('organization_id', $variant->organization_id)->where('sku', $v->sku)->whereKeyNot($variant->getKey())->exists()) {
            $variant->sku = $v->sku;
        }
    }

    private function linkChannel(WooCommerceIntegration $integration, Product $product, ProductVariant $variant, NormalizedWooProduct $woo, ?NormalizedWooVariant $wooVariant): void
    {
        // Product-level anchor (variable products keep product_variant_id null).
        // Keyed on the pre-existing (organization, source, remote id) unique so a
        // legacy CSV-import mapping is adopted rather than colliding.
        $this->upsertIdentifier([
            'organization_id' => $product->organization_id,
            'source' => WooCommerceIntegration::CHANNEL,
            'external_product_id' => (string) $woo->remoteId,
        ], [
            'woocommerce_integration_id' => $integration->getKey(),
            'product_id' => $product->getKey(),
            'product_variant_id' => $woo->isVariable() ? null : $variant->getKey(),
            'remote_parent_id' => null,
            'remote_variation_id' => null,
            'external_stock_status' => $woo->stockStatus,
            'remote_modified_at' => $woo->dateModified,
            'last_synced_at' => now(),
        ]);

        if ($wooVariant && $wooVariant->remoteVariationId) {
            $this->upsertIdentifier([
                'organization_id' => $product->organization_id,
                'source' => WooCommerceIntegration::CHANNEL,
                'external_product_id' => (string) $wooVariant->remoteVariationId,
            ], [
                'woocommerce_integration_id' => $integration->getKey(),
                'product_id' => $product->getKey(),
                'product_variant_id' => $variant->getKey(),
                'remote_parent_id' => (string) $woo->remoteId,
                'remote_variation_id' => (string) $wooVariant->remoteVariationId,
                'external_stock_status' => $wooVariant->stockStatus,
                'remote_modified_at' => $woo->dateModified,
                'last_synced_at' => now(),
            ]);
        }
    }

    /**
     * updateOrCreate-style upsert without mass assignment — ProductChannelIdentifier
     * is fully guarded ($guarded = ['*']), so every attribute is set explicitly.
     *
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $values
     */
    private function upsertIdentifier(array $key, array $values): void
    {
        $identifier = ProductChannelIdentifier::query()->where($key)->first() ?? new ProductChannelIdentifier;

        foreach ($key + $values as $attribute => $value) {
            $identifier->{$attribute} = $value;
        }

        $identifier->save();
    }

    /* -------------------------------------------------------------------------
     |  Stock reconciliation (through the inventory ledger, never a direct write)
     * ---------------------------------------------------------------------- */

    private function reconcileStock(WooCommerceIntegration $integration, Organization $organization, User $actor, ProductVariant $variant, NormalizedWooVariant $wooVariant, WooCommerceSyncRun $run): void
    {
        if (! $wooVariant->manageStock || $wooVariant->stockQuantity === null) {
            return;
        }

        $warehouse = Warehouse::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($integration->default_warehouse_id)
            ->where('status', 'active')
            ->first();
        if (! $warehouse) {
            $this->recordIssue($run, null, 'Stock non synchronisé : entrepôt cible introuvable ou inactif.');

            return;
        }

        $target = InventoryQuantity::normalize((string) max(0, $wooVariant->stockQuantity));
        $current = InventoryBalance::query()
            ->where('organization_id', $organization->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->value('on_hand') ?? '0.0000';

        $delta = InventoryQuantity::subtract($target, $current);
        if (InventoryQuantity::compare($delta, '0.0000') === 0) {
            return;
        }

        $reference = "WOO-SYNC-{$run->getKey()}-V{$variant->getKey()}";
        $reason = 'Synchronisation WooCommerce';

        try {
            $hasMovements = InventoryMovement::query()
                ->where('organization_id', $organization->getKey())
                ->where('warehouse_id', $warehouse->getKey())
                ->where('product_variant_id', $variant->getKey())
                ->exists();

            if (! $hasMovements && InventoryQuantity::compare($target, '0.0000') > 0) {
                $this->openingStock->execute($actor, $organization, $warehouse, $variant, $target, $reason, $reference);
            } else {
                $isIncrease = InventoryQuantity::compare($delta, '0.0000') > 0;
                $this->adjustInventory->execute(
                    $actor, $organization, $warehouse, $variant,
                    $isIncrease ? InventoryMovementType::AdjustmentIn : InventoryMovementType::AdjustmentOut,
                    $isIncrease ? $delta : ltrim($delta, '-'),
                    $reason, $reference,
                );
            }
            $run->increment('stock_adjustments');
        } catch (ValidationException $exception) {
            $this->recordIssue($run, null, 'Stock non réconcilié pour '.($variant->sku ?? "variante #{$variant->getKey()}").' : '.$this->firstMessage($exception));
        }
    }

    private function stockSyncEnabled(WooCommerceIntegration $integration, User $actor): bool
    {
        return $integration->sync_stock
            && $integration->default_warehouse_id
            && ($actor->hasPermission($integration->organization_id, 'inventory.adjust')
                || $actor->hasPermission($integration->organization_id, 'inventory.opening'));
    }

    /* -------------------------------------------------------------------------
     |  Small helpers
     * ---------------------------------------------------------------------- */

    private function resolveBrand(Organization $organization, string $name): int
    {
        $existing = Brand::query()
            ->where('organization_id', $organization->getKey())
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->first();
        if ($existing) {
            return $existing->getKey();
        }

        $base = Str::slug($name) ?: 'marque';
        $slug = $base;
        $suffix = 2;
        while (Brand::query()->where('organization_id', $organization->getKey())->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        $brand = new Brand;
        $brand->organization_id = $organization->getKey();
        $brand->name = trim($name);
        $brand->slug = $slug;
        $brand->status = 'active';
        $brand->save();

        return $brand->getKey();
    }

    /** @return array{includes_tax: bool, label: string, detected: bool} */
    private function resolvePriceMeaning(WooCommerceIntegration $integration, WooCommerceClient $client): array
    {
        if ($integration->prices_include_tax !== null) {
            return ['includes_tax' => $integration->prices_include_tax, 'label' => $integration->prices_include_tax ? 'TTC' : 'HT', 'detected' => false];
        }

        $detected = $this->safe(fn () => $client->getPricesIncludeTax());
        if ($detected !== null) {
            $integration->forceFill(['prices_include_tax' => $detected])->save();

            return ['includes_tax' => $detected, 'label' => $detected ? 'TTC' : 'HT', 'detected' => true];
        }

        // Undetected: default to TTC (the common retail case) but say so in the run message.
        return ['includes_tax' => true, 'label' => 'TTC', 'detected' => false];
    }

    /** @param list<array<string, mixed>> $out */
    private function fetchVariations(WooCommerceClient $client, int $productId): array
    {
        $all = [];
        $page = 1;
        $maxPages = (int) config('woocommerce.max_pages', 2000);

        do {
            $result = $client->getProductVariations($productId, [
                'per_page' => (int) config('woocommerce.per_page', 50),
                'page' => $page,
            ]);
            $all = array_merge($all, $result['items']);
            $totalPages = max(1, $result['total_pages']);
            $page++;
        } while ($page <= $totalPages && $page <= $maxPages);

        return $all;
    }

    private function mappedProductCount(WooCommerceIntegration $integration): int
    {
        return ProductChannelIdentifier::query()
            ->where('organization_id', $integration->organization_id)
            ->where('woocommerce_integration_id', $integration->getKey())
            ->where('source', WooCommerceIntegration::CHANNEL)
            ->whereNull('remote_variation_id')
            ->distinct('product_id')
            ->count('product_id');
    }

    private function failRun(WooCommerceSyncRun $run, string $message): void
    {
        $run->status = WooCommerceSyncRun::STATUS_FAILED;
        $run->message = $message;
        $run->completed_at = now();
        $run->save();
    }

    private function recordIssue(WooCommerceSyncRun $run, mixed $remoteId, string $message): void
    {
        $errors = $run->errors ?? [];
        $errors[] = ['remote_id' => $remoteId !== null ? (string) $remoteId : null, 'message' => Str::limit($message, 500, '')];
        $run->errors = array_slice($errors, -200);
        $run->saveQuietly();
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = preg_replace('/\s+/', ' ', $exception->getMessage()) ?: 'Erreur de synchronisation.';
        $message = preg_replace('/(consumer_secret|secret|api[_-]?key|password)\s*[=:]\s*\S+/i', '$1=[masqué]', $message) ?? $message;

        return Str::limit($message, 400, '');
    }

    private function firstMessage(ValidationException $exception): string
    {
        $all = $exception->errors();
        $first = reset($all);

        return is_array($first) ? (string) ($first[0] ?? 'Refusé.') : (string) $first;
    }

    /** @template T @param callable():T $callback @return T|null */
    private function safe(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (WooCommerceApiException) {
            return null;
        }
    }
}
