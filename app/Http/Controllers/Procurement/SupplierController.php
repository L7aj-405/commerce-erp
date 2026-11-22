<?php

namespace App\Http\Controllers\Procurement;

use App\Actions\Procurement\SaveSupplierAction;
use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\ActiveTenantContext;
use App\Services\Procurement\SupplierCatalogQuery;
use App\Services\Procurement\SupplierFiscalAdvisor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SupplierController extends Controller
{
    /** Non-sensitive fiscal identity fields exposed in lists and detail. */
    private const FISCAL_LIST_FIELDS = ['ice', 'tax_identifier', 'rc_number', 'default_payment_term_days', 'default_currency_code'];

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
            ->through(fn (Supplier $supplier) => [
                ...$supplier->only(['id', 'name', 'contact_person', 'phone', 'email', 'address', 'notes', 'active', ...self::FISCAL_LIST_FIELDS]),
                // The IBAN itself is never listed.
                'has_bank_iban' => filled($supplier->bank_iban),
            ]);

        return Inertia::render('Procurement/Suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => $filters,
            'can' => ['manage' => $request->user()->hasPermission($organization, 'suppliers.manage')],
        ]);
    }

    /** Supplier detail with its supplied products ("Produits fournis"). */
    public function show(Request $request, Supplier $supplier, SupplierCatalogQuery $catalog): InertiaResponse
    {
        $this->authorize('view', $supplier);
        $user = $request->user();
        $organizationId = (int) $supplier->organization_id;
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
        $canViewProducts = $user->hasPermission($organizationId, 'products.view');

        return Inertia::render('Procurement/Suppliers/Show', [
            'supplier' => [
                ...$supplier->only(['id', 'name', 'contact_person', 'phone', 'email', 'address', 'notes', 'active', ...self::FISCAL_LIST_FIELDS]),
                'has_bank_iban' => filled($supplier->bank_iban),
                // Full IBAN only for users who can edit the supplier; read-only users see it masked.
                'bank_iban' => $user->hasPermission($organizationId, 'suppliers.manage') ? $supplier->bank_iban : null,
                'bank_iban_masked' => SupplierFiscalAdvisor::maskIban($supplier->bank_iban),
            ],
            'warnings' => app(SupplierFiscalAdvisor::class)->advise($supplier),
            'relations' => $canViewProducts
                ? $catalog->forSupplier($organizationId, (int) $supplier->getKey(), $filters, SupplierCatalogQuery::canViewPrices($user, $organizationId), 25, (int) $request->integer('page', 1))->withQueryString()
                : null,
            'filters' => $filters,
            'can' => [
                'manage' => $canViewProducts && $user->hasPermission($organizationId, 'supplier_catalog.manage'),
                'editSupplier' => $user->hasPermission($organizationId, 'suppliers.manage'),
                'viewPrices' => SupplierCatalogQuery::canViewPrices($user, $organizationId),
            ],
            'defaultCurrency' => config('platform.currency_code', 'MAD'),
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, SaveSupplierAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Supplier::class, $organization]);
        $supplier = $action->execute($request->user(), $organization, $request->validate($this->rules()));

        return $this->saved($supplier, 'Fournisseur enregistré.');
    }

    public function update(Request $request, Supplier $supplier, SaveSupplierAction $action): RedirectResponse
    {
        $this->authorize('update', $supplier);
        $supplier = $action->execute($request->user(), $supplier->organization, $request->validate($this->rules()), $supplier);

        return $this->saved($supplier, 'Fournisseur mis à jour.');
    }

    /** The supplier is always saved; fiscal-identity hints are advisory and ride along with the confirmation. */
    private function saved(Supplier $supplier, string $message): RedirectResponse
    {
        $warnings = app(SupplierFiscalAdvisor::class)->advise($supplier);
        $flash = $warnings === [] ? $message : $message.' Attention : '.implode(' ', array_column($warnings, 'message'));

        return back()->with('success', $flash)->with('supplier_warnings', $warnings);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return SaveSupplierAction::rules();
    }
}
