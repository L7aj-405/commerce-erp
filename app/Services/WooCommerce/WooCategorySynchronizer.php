<?php

namespace App\Services\WooCommerce;

use App\Models\Category;
use App\Models\WooCommerceCategoryMapping;
use App\Models\WooCommerceIntegration;
use Illuminate\Support\Str;

/**
 * Maps WooCommerce product categories to ERP Categories by their REMOTE id
 * (never by name alone), so repeated syncs never duplicate a category. Parent
 * relationships from the store's full category tree are preserved where the ERP
 * hierarchy allows it (cycle-safe, depth-capped).
 *
 * Build once per run with `prime()`, then call `map()` per category.
 */
class WooCategorySynchronizer
{
    /** @var array<int, array{name: string, parent: int}> */
    private array $remoteTree = [];

    /** @var array<int, int> remote category id => ERP category id, resolved this run */
    private array $resolved = [];

    public function __construct(private readonly WooCommerceIntegration $integration) {}

    /** @param array<int, array<string, mixed>> $remoteCategories the store's full products/categories list */
    public function prime(array $remoteCategories): void
    {
        foreach ($remoteCategories as $category) {
            if (! isset($category['id'])) {
                continue;
            }
            $this->remoteTree[(int) $category['id']] = [
                'name' => trim((string) ($category['name'] ?? '')) ?: "Catégorie #{$category['id']}",
                'parent' => (int) ($category['parent'] ?? 0),
            ];
        }
    }

    /** @return array<int, int> remote id => ERP id for every category resolved this run */
    public function resolvedMap(): array
    {
        return $this->resolved;
    }

    public function map(NormalizedWooCategory $category): Category
    {
        return $this->resolveRemote($category->remoteId, $category->name, 0);
    }

    private function resolveRemote(int $remoteId, string $fallbackName, int $depth): Category
    {
        $mapping = WooCommerceCategoryMapping::query()
            ->where('organization_id', $this->integration->organization_id)
            ->where('woocommerce_integration_id', $this->integration->getKey())
            ->where('remote_category_id', $remoteId)
            ->first();

        if ($mapping) {
            $this->resolved[$remoteId] = (int) $mapping->category_id;

            return $mapping->category;
        }

        $node = $this->remoteTree[$remoteId] ?? ['name' => $fallbackName, 'parent' => 0];

        $parentId = null;
        if ($depth < 6 && ($node['parent'] ?? 0) > 0 && ($node['parent'] !== $remoteId)) {
            $parentId = $this->resolveRemote((int) $node['parent'], "Catégorie #{$node['parent']}", $depth + 1)->getKey();
        }

        $category = $this->createErpCategory($node['name'], $parentId);

        // These models are fully guarded ($guarded = ['*']); assign explicitly.
        $mapping = new WooCommerceCategoryMapping;
        $mapping->organization_id = $this->integration->organization_id;
        $mapping->woocommerce_integration_id = $this->integration->getKey();
        $mapping->remote_category_id = $remoteId;
        $mapping->category_id = $category->getKey();
        $mapping->remote_name = $node['name'];
        $mapping->save();

        $this->resolved[$remoteId] = $category->getKey();

        return $category;
    }

    private function createErpCategory(string $name, ?int $parentId): Category
    {
        $organizationId = (int) $this->integration->organization_id;

        // Reuse an existing same-name category rather than making a near-duplicate.
        $existing = Category::query()
            ->where('organization_id', $organizationId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
        if ($existing) {
            return $existing;
        }

        $base = Str::slug($name) ?: 'categorie';
        $slug = $base;
        $suffix = 2;
        while (Category::query()->where('organization_id', $organizationId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        $category = new Category;
        $category->organization_id = $organizationId;
        $category->parent_id = $parentId;
        $category->name = $name;
        $category->slug = $slug;
        $category->status = 'active';
        $category->save();

        return $category;
    }
}
