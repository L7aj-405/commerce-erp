<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\ActiveTenantContext;
use App\Services\CatalogReferenceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    public function index(ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [Brand::class, $organization]);

        return Inertia::render('Catalog/ReferenceData', ['kind' => 'brands', 'title' => 'Brands', 'records' => $organization->brands()->orderBy('name')->get()]);
    }

    public function store(Request $request, ActiveTenantContext $context, CatalogReferenceManager $manager): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Brand::class, $organization]);
        $manager->save($request->user(), $organization, new Brand, $request->validate($this->rules($organization->id)), 'brand.created');

        return back();
    }

    public function update(Request $request, Brand $brand, CatalogReferenceManager $manager): RedirectResponse
    {
        $this->authorize('update', $brand);
        $manager->save($request->user(), $brand->organization, $brand, $request->validate($this->rules($brand->organization_id, $brand)), 'brand.updated');

        return back();
    }

    private function rules(int $organizationId, ?Brand $brand = null): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'slug' => ['required', 'string', 'max:255', Rule::unique('brands')->where('organization_id', $organizationId)->ignore($brand)], 'status' => ['required', Rule::enum(CatalogStatus::class)]];
    }
}
