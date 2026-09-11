<?php

namespace App\Http\Controllers\Procurement;

use App\Actions\Procurement\SaveSupplierAction;
use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\ActiveTenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SupplierController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): InertiaResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [Supplier::class, $organization]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'in:1,0'],
        ]);

        $suppliers = Supplier::query()
            ->where('organization_id', $organization->getKey())
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('contact_person', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when(isset($filters['active']), fn ($query) => $query->where('active', (bool) $filters['active']))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Supplier $supplier) => $supplier->only([
                'id', 'name', 'contact_person', 'phone', 'email', 'address', 'notes', 'active',
            ]));

        return Inertia::render('Procurement/Suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => $filters,
            'can' => ['manage' => $request->user()->hasPermission($organization, 'suppliers.manage')],
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, SaveSupplierAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Supplier::class, $organization]);
        $action->execute($request->user(), $organization, $request->validate($this->rules()));

        return back()->with('success', 'Fournisseur enregistré.');
    }

    public function update(Request $request, Supplier $supplier, SaveSupplierAction $action): RedirectResponse
    {
        $this->authorize('update', $supplier);
        $action->execute($request->user(), $supplier->organization, $request->validate($this->rules()), $supplier);

        return back()->with('success', 'Fournisseur mis à jour.');
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
