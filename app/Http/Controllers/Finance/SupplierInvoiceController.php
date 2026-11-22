<?php

namespace App\Http\Controllers\Finance;

use App\Actions\SupplierInvoices\SaveSupplierInvoiceDraftAction;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreSupplierInvoiceRequest;
use App\Http\Requests\Finance\UpdateSupplierInvoiceRequest;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\TaxRate;
use App\Services\ActiveTenantContext;
use App\Services\Sandbox\SandboxContext;
use App\Services\SupplierInvoices\SupplierInvoicePresenter;
use App\Services\SupplierInvoices\SupplierInvoiceSettings;
use App\Services\SupplierInvoices\SupplierInvoiceStoreAccess;
use App\Services\SupplierInvoices\SupplierInvoiceWarnings;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierInvoiceController extends Controller implements HasMiddleware
{
    /** Kill switch: the whole feature answers 404 until enabled (and always in Test Mode). */
    public static function middleware(): array
    {
        return [function (Request $request, Closure $next) {
            abort_if(app(SandboxContext::class)->isSandbox(), 404);
            abort_unless(app(SupplierInvoiceSettings::class)->enabled(), 404);

            return $next($request);
        }];
    }

    public function __construct(
        private readonly ActiveTenantContext $context,
        private readonly SupplierInvoiceStoreAccess $stores,
        private readonly SupplierInvoicePresenter $presenter,
        private readonly SupplierInvoiceWarnings $warnings,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SupplierInvoice::class);
        $organization = $this->context->organizationOrFail();
        $visible = $this->stores->visibleStoreIds($request->user(), $organization);
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SupplierInvoiceStatus::class)],
            'supplier_id' => ['nullable', 'integer'],
            'store_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $invoices = SupplierInvoice::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('store_id', $visible)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['supplier_id'] ?? null, fn (Builder $query, $id) => $query->where('supplier_id', $id))
            ->when($filters['store_id'] ?? null, fn (Builder $query, $id) => $query->where('store_id', $id))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $like = '%'.addcslashes(trim($search), '%_\\').'%';
                $query->where(fn (Builder $q) => $q->where('invoice_number', 'like', $like)->orWhere('internal_number', 'like', $like)
                    ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', $like)));
            })
            ->with(['supplier:id,name,ice', 'store:id,name,code', 'organization'])
            ->withCount('attachments')
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->paginate(20)->withQueryString();
        $invoices->through(fn (SupplierInvoice $invoice) => $this->presenter->row($invoice, count($this->warnings->forInvoice($invoice->load('installments')))));

        $user = $request->user();

        return Inertia::render('Finance/SupplierInvoices/Index', [
            'invoices' => $invoices,
            'filters' => ['status' => $filters['status'] ?? null, 'supplier_id' => $filters['supplier_id'] ?? null, 'store_id' => $filters['store_id'] ?? null, 'search' => $filters['search'] ?? null],
            'stores' => $this->storeOptions($organization, $visible, false),
            'suppliers' => Supplier::query()->where('organization_id', $organization->getKey())->orderBy('name')->get(['id', 'name']),
            'can' => [
                'create' => $user->can('create', SupplierInvoice::class) && $this->storeOptions($organization, $visible, true)->isNotEmpty(),
                'approve' => $user->hasPermission($organization, 'supplier_invoices.approve'),
                'void' => $user->hasPermission($organization, 'supplier_invoices.void'),
            ],
        ]);
    }

    public function show(SupplierInvoice $supplierInvoice): Response
    {
        $this->authorize('view', $supplierInvoice);
        $invoice = $this->loadDetail($supplierInvoice);
        $user = request()->user();

        return Inertia::render('Finance/SupplierInvoices/Show', [
            'invoice' => $this->presenter->detail($invoice),
            'matching' => $this->presenter->matching($invoice),
            'warnings' => $this->warnings->forInvoice($invoice),
            'can' => [
                'edit' => $user->can('update', $invoice),
                'approve' => $user->can('approve', $invoice),
                'void' => $user->can('void', $invoice),
                'attach' => false, // enabled by the attachment work
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', SupplierInvoice::class);

        return $this->form($request, null);
    }

    public function edit(Request $request, SupplierInvoice $supplierInvoice): Response
    {
        $this->authorize('update', $supplierInvoice);

        return $this->form($request, $this->loadDetail($supplierInvoice));
    }

    public function store(StoreSupplierInvoiceRequest $request, SaveSupplierInvoiceDraftAction $action): RedirectResponse
    {
        $result = $action->execute($request->user(), $this->context->organizationOrFail(), $request->validated());

        return redirect()->route('finance.supplier-invoices.show', $result['invoice'])
            ->with('success', $result['created'] ? 'Facture fournisseur enregistrée en brouillon.' : 'Facture fournisseur déjà enregistrée.');
    }

    public function update(UpdateSupplierInvoiceRequest $request, SupplierInvoice $supplierInvoice, SaveSupplierInvoiceDraftAction $action): RedirectResponse
    {
        $result = $action->execute($request->user(), $this->context->organizationOrFail(), $request->validated(), $supplierInvoice);

        return redirect()->route('finance.supplier-invoices.show', $result['invoice'])->with('success', 'Brouillon mis à jour.');
    }

    private function loadDetail(SupplierInvoice $invoice): SupplierInvoice
    {
        return $invoice->load([
            'organization', 'supplier', 'store', 'purchaseOrder', 'createdBy', 'approvedBy', 'voidedBy', 'attachments',
            'lines.productVariant.product', 'installments.preferredFinancialAccount',
        ]);
    }

    private function form(Request $request, ?SupplierInvoice $invoice): Response
    {
        $organization = $this->context->organizationOrFail();
        $visible = $this->stores->visibleStoreIds($request->user(), $organization);
        $orgId = $organization->getKey();

        return Inertia::render('Finance/SupplierInvoices/Form', [
            'invoice' => $invoice ? $this->presenter->detail($invoice) : null,
            'suppliers' => Supplier::query()->where('organization_id', $orgId)->where('active', true)->orderBy('name')
                ->get(['id', 'name', 'ice', 'default_payment_term_days', 'default_currency_code']),
            'stores' => $this->storeOptions($organization, $visible, true),
            'taxRates' => TaxRate::query()->where('organization_id', $orgId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'rate'])
                ->map(fn (TaxRate $rate) => ['id' => $rate->id, 'name' => $rate->name, 'rate' => (string) $rate->rate])->values(),
            'purchaseOrders' => PurchaseOrder::query()->where('organization_id', $orgId)->whereIn('store_id', $visible)
                ->whereNotIn('status', [PurchaseOrderStatus::Cancelled->value, PurchaseOrderStatus::Draft->value])
                ->with('lines')->orderByDesc('id')->limit(250)->get()
                ->map(fn (PurchaseOrder $order) => [
                    'id' => $order->id, 'number' => $order->purchase_order_number, 'supplier_id' => $order->supplier_id, 'store_id' => $order->store_id,
                    'currency_code' => $order->currency_code, 'status' => $order->status->value,
                    'lines' => $order->lines->map(fn ($line) => [
                        'id' => $line->id, 'product_variant_id' => $line->product_variant_id, 'quantity' => (string) $line->quantity,
                        'unit_price' => $line->unit_price === null ? null : (string) $line->unit_price,
                    ])->values(),
                ])->values(),
            'accounts' => FinancialAccount::query()->where('organization_id', $orgId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code', 'currency_code']),
            'settings' => [
                'rounding_tolerance' => app(SupplierInvoiceSettings::class)->roundingTolerance(),
                'require_distinct_approver' => app(SupplierInvoiceSettings::class)->requireDistinctApprover(),
                'money_scale' => app(SupplierInvoiceSettings::class)->moneyScale(),
            ],
            'row_version' => $invoice?->row_version,
        ]);
    }

    /** @param list<int> $visible */
    private function storeOptions(Organization $organization, array $visible, bool $activeOnly)
    {
        return Store::query()->where('organization_id', $organization->getKey())->whereIn('id', $visible)
            ->when($activeOnly, fn (Builder $query) => $query->where('status', 'active'))
            ->orderBy('name')->get(['id', 'name', 'code']);
    }
}
