<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Models\TaxRate;
use App\Services\ActiveTenantContext;
use App\Services\CatalogReferenceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaxRateController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [TaxRate::class, $organization]);

        return Inertia::render('Catalog/ReferenceData', [
            'kind' => 'tax-rates',
            'title' => 'Taxes',
            'records' => $organization->taxRates()->orderBy('name')->get(),
            'stores' => $request->user()->hasPermission($organization, 'stores.update')
                ? $organization->stores()->orderBy('name')->get(['id', 'name', 'code', 'default_tax_rate_id'])
                : [],
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, CatalogReferenceManager $manager): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [TaxRate::class, $organization]);
        $manager->save($request->user(), $organization, new TaxRate, $request->validate($this->rules($organization->id)), 'tax_rate.created');

        return back();
    }

    public function update(Request $request, TaxRate $taxRate, CatalogReferenceManager $manager): RedirectResponse
    {
        $this->authorize('update', $taxRate);
        $manager->save($request->user(), $taxRate->organization, $taxRate, $request->validate($this->rules($taxRate->organization_id, $taxRate)), 'tax_rate.updated');

        return back();
    }

    private function rules(int $organizationId, ?TaxRate $taxRate = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('tax_rates')->where('organization_id', $organizationId)->ignore($taxRate)],
            'rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
        ];
    }
}
