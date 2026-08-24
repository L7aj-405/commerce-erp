<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\ActiveTenantContext;
use App\Services\CatalogReferenceManager;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [Category::class, $organization]);
        $records = $organization->categories()->with('parent:id,name')->orderBy('name')->get();

        return Inertia::render('Catalog/ReferenceData', ['kind' => 'categories', 'title' => 'Categories', 'records' => $records, 'parents' => $records->map->only(['id', 'name'])]);
    }

    public function store(Request $request, ActiveTenantContext $context, CatalogReferenceManager $manager): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Category::class, $organization]);
        $manager->save($request->user(), $organization, new Category, $request->validate($this->rules($organization->id)), 'category.created');

        return back();
    }

    public function update(Request $request, Category $category, CatalogReferenceManager $manager): RedirectResponse
    {
        $this->authorize('update', $category);
        $manager->save($request->user(), $category->organization, $category, $request->validate($this->rules($category->organization_id, $category)), 'category.updated');

        return back();
    }

    private function rules(int $organizationId, ?Category $category = null): array
    {
        $parentRules = [
            'bail',
            'nullable',
            'integer',
            Rule::notIn(array_filter([$category?->id])),
            Rule::exists('categories', 'id')->where('organization_id', $organizationId),
        ];

        if ($category) {
            $parentRules[] = function (string $attribute, mixed $value, Closure $fail) use ($category, $organizationId): void {
                $ancestorId = $value ? (int) $value : null;
                $visited = [];

                while ($ancestorId) {
                    if ($ancestorId === (int) $category->getKey() || isset($visited[$ancestorId])) {
                        $fail('The selected parent would create a category cycle.');

                        return;
                    }

                    $visited[$ancestorId] = true;
                    $parentId = Category::query()
                        ->where('organization_id', $organizationId)
                        ->whereKey($ancestorId)
                        ->value('parent_id');
                    $ancestorId = $parentId ? (int) $parentId : null;
                }
            };
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('categories')->where('organization_id', $organizationId)->ignore($category)],
            'parent_id' => $parentRules,
            'status' => ['required', Rule::enum(CatalogStatus::class)],
        ];
    }
}
