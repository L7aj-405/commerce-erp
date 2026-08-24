<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Models\UnitOfMeasure;
use App\Services\ActiveTenantContext;
use App\Services\CatalogReferenceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UnitOfMeasureController extends Controller
{
    public function index(ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [UnitOfMeasure::class, $organization]);

        return Inertia::render('Catalog/ReferenceData', ['kind' => 'units', 'title' => 'Units of Measure', 'records' => $organization->unitsOfMeasure()->orderBy('name')->get()]);
    }

    public function store(Request $request, ActiveTenantContext $context, CatalogReferenceManager $manager): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [UnitOfMeasure::class, $organization]);
        $manager->save($request->user(), $organization, new UnitOfMeasure, $request->validate($this->rules($organization->id)), 'unit.created');

        return back();
    }

    public function update(Request $request, UnitOfMeasure $unit, CatalogReferenceManager $manager): RedirectResponse
    {
        $this->authorize('update', $unit);
        $manager->save($request->user(), $unit->organization, $unit, $request->validate($this->rules($unit->organization_id, $unit)), 'unit.updated');

        return back();
    }

    private function rules(int $organizationId, ?UnitOfMeasure $unit = null): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'symbol' => ['required', 'string', 'max:32', Rule::unique('units_of_measure')->where('organization_id', $organizationId)->ignore($unit)], 'status' => ['required', Rule::enum(CatalogStatus::class)]];
    }
}
