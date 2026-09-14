<?php

namespace App\Http\Controllers\Quotations;

use App\Actions\Quotations\CreateQuotationAction;
use App\Actions\Quotations\DecideQuotationAction;
use App\Actions\Quotations\DuplicateQuotationAction;
use App\Actions\Quotations\IssueQuotationAction;
use App\Actions\Quotations\StartQuotationRevisionAction;
use App\Actions\Quotations\UpdateQuotationAction;
use App\Enums\CatalogStatus;
use App\Enums\QuotationStatus;
use App\Enums\WarehouseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotations\StoreQuotationRequest;
use App\Http\Requests\Quotations\UpdateQuotationRequest;
use App\Models\InventoryBalance;
use App\Models\NonStockItem;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\TaxRate;
use App\Services\ActiveTenantContext;
use App\Services\ProductPriceResolver;
use App\Services\QuotationDocumentSettings;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuotationController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $store = $context->storeOrFail();
        $this->authorize('viewAny', [Quotation::class, $organization, $store]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(QuotationStatus::class)],
            'quotation_date' => ['nullable', 'date_format:Y-m-d'],
            'validity' => ['nullable', 'in:valid,expired'],
        ]);

        $quotations = Quotation::query()
            ->where('organization_id', $organization->getKey())->where('store_id', $store->getKey())
            ->when($filters['search'] ?? null, fn ($q, string $s) => $q->where(fn ($q) => $q
                ->where('quotation_number', 'like', "%{$s}%")
                ->orWhere('customer_name', 'like', "%{$s}%")
                ->orWhere('customer_company', 'like', "%{$s}%")
                ->orWhere('customer_phone', 'like', "%{$s}%")
                ->orWhere('customer_email', 'like', "%{$s}%")))
            ->when($filters['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            ->when($filters['quotation_date'] ?? null, fn ($q, string $d) => $q->whereDate('quotation_date', $d))
            ->when(($filters['validity'] ?? null) === 'expired', fn ($q) => $q->whereNotNull('valid_until')->whereDate('valid_until', '<', now()->toDateString()))
            ->when(($filters['validity'] ?? null) === 'valid', fn ($q) => $q->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', now()->toDateString())))
            ->with(['createdBy:id,name'])
            ->latest('quotation_date')->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('Quotations/Index', ['quotations' => $quotations, 'filters' => $filters]);
    }

    public function create(Request $request, ActiveTenantContext $context, QuotationDocumentSettings $settings): Response
    {
        $organization = $context->organizationOrFail();
        $store = $context->storeOrFail();
        $this->authorize('create', [Quotation::class, $organization, $store]);

        return Inertia::render('Quotations/Create', [
            'defaults' => $settings->settings($organization),
            'currencyCode' => config('platform.currency_code', 'MAD'),
            'can' => ['createCustomer' => $request->user()->hasPermission($organization, 'customers.create')],
        ]);
    }

    /** Lightweight customer search for the Devis editor's client picker. */
    public function customerSearch(Request $request, ActiveTenantContext $context): JsonResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless(
            $request->user()->hasPermission($organization, 'quotations.create')
            && $request->user()->hasPermission($organization, 'customers.view'),
            403,
        );
        $search = trim((string) $request->query('search', ''));

        $customers = \App\Models\Customer::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', 'active')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('display_name', 'like', "%{$search}%")
                ->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('display_name')
            ->limit(15)
            ->get(['id', 'type', 'display_name', 'company_name', 'phone', 'email', 'tax_identifier', 'billing_address']);

        return response()->json(['data' => $customers]);
    }

    /** Compact inline Customer creation from the Devis editor. */
    public function storeCustomer(Request $request, ActiveTenantContext $context, \App\Services\CustomerManager $manager): JsonResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [\App\Models\Customer::class, $organization]);

        $data = $request->validate([
            'type' => ['required', \Illuminate\Validation\Rule::enum(\App\Enums\CustomerType::class)],
            'display_name' => ['nullable', 'string', 'max:255', 'required_if:type,individual'],
            'company_name' => ['nullable', 'string', 'max:255', 'required_if:type,company'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_identifier' => ['nullable', 'string', 'max:128'],
            'billing_address' => ['nullable', 'string', 'max:5000'],
        ]);

        $customer = $manager->create($request->user(), $organization, [
            'type' => $data['type'],
            'display_name' => $data['type'] === 'company' ? trim((string) $data['company_name']) : trim((string) $data['display_name']),
            'company_name' => $data['type'] === 'company' ? trim((string) $data['company_name']) : null,
            'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
            'email' => filled($data['email'] ?? null) ? trim((string) $data['email']) : null,
            'tax_identifier' => filled($data['tax_identifier'] ?? null) ? trim((string) $data['tax_identifier']) : null,
            'billing_address' => filled($data['billing_address'] ?? null) ? trim((string) $data['billing_address']) : null,
            'notes' => null,
            'status' => \App\Enums\CustomerStatus::Active->value,
        ]);

        return response()->json(['data' => $customer->only(['id', 'type', 'display_name', 'company_name', 'phone', 'email', 'tax_identifier', 'billing_address'])], 201);
    }

    public function store(StoreQuotationRequest $request, ActiveTenantContext $context, CreateQuotationAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $store = $context->storeOrFail();
        $this->authorize('create', [Quotation::class, $organization, $store]);

        $quotation = $action->execute($request->user(), $organization, $store, $request->validated());

        return redirect()->route('quotations.show', $quotation);
    }

    public function show(Request $request, Quotation $quotation): Response
    {
        $this->authorize('view', $quotation);
        $quotation->load([
            'lines', 'customer:id,display_name,company_name', 'createdBy:id,name', 'issuedBy:id,name',
            'convertedSalesOrder:id,organization_id,order_number,status',
            'revisedFrom:id,quotation_number,revision_number',
            'rootQuotation:id,quotation_number',
        ]);

        // On-screen preview needs no heavy logo data URI.
        $seller = $quotation->seller_snapshot ?? [];
        $hasLogo = ! empty($seller['logo']);
        unset($seller['logo']);
        $quotation->setAttribute('seller_snapshot', $seller);

        $isOfficial = $quotation->status->isOfficial();
        $hasDiscount = Decimal::compare($quotation->discount_total, '0') !== 0
            || $quotation->lines->contains(fn ($l) => Decimal::compare($l->discount_amount, '0') !== 0);

        $history = $this->revisionHistory($quotation);
        $currentEntry = collect($history)->firstWhere('is_current', true);
        $isCurrentVersion = $history === [] ? true : ($currentEntry['id'] ?? null) === $quotation->getKey();
        $hasActiveRevisionDraft = collect($history)->contains(
            fn ($entry) => $entry['revision_number'] > 0 && $entry['status'] === QuotationStatus::Draft->value,
        );

        // Sharing (signed PDF link + prefilled WhatsApp) is only meaningful for
        // the currently-live official version — never a draft, never a
        // superseded revision.
        $canShare = $isOfficial && $isCurrentVersion && $quotation->status !== QuotationStatus::Superseded;
        $sharePdfUrl = $canShare
            ? \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'quotations.shared-pdf',
                now()->addDays((int) config('documents.share_link_ttl_days', 14)),
                $quotation,
            ) : null;

        return Inertia::render('Quotations/Show', [
            'quotation' => $quotation,
            'hasDiscount' => $hasDiscount,
            'sellerHasLogo' => $hasLogo,
            'accentColor' => $seller['accent_color'] ?? \App\Services\DocumentSellerProfile::DEFAULT_ACCENT_COLOR,
            // The Draft "Aperçu PDF" opens the REAL server-rendered Devis PDF —
            // same Dompdf architecture as an issued Devis, BROUILLON state only.
            'previewUrl' => route('quotations.pdf', $quotation),
            'searchUrl' => route('quotations.search', $quotation),
            'taxRates' => TaxRate::query()->where('organization_id', $quotation->organization_id)
                ->where('status', CatalogStatus::Active->value)->orderBy('name')->get(['id', 'name', 'rate', 'is_default']),
            'warehouses' => \App\Models\Warehouse::query()->where('organization_id', $quotation->organization_id)
                ->where('status', WarehouseStatus::Active->value)->orderBy('name')->get(['id', 'name', 'code']),
            'isPastValidity' => $quotation->isPastValidity(),
            'isCurrentVersion' => $isCurrentVersion,
            'history' => $history,
            'sharing' => $canShare ? [
                'pdfUrl' => $sharePdfUrl,
                'email' => $quotation->customer_email,
                'phone' => $quotation->customer_phone,
                'whatsappMessage' => $this->whatsappMessage($quotation),
            ] : null,
            'can' => [
                'update' => $request->user()->can('update', $quotation),
                'issue' => $request->user()->can('issue', $quotation),
                'decide' => $request->user()->can('decide', $quotation) && $isCurrentVersion,
                'convert' => $request->user()->can('convert', $quotation)
                    && $quotation->status !== QuotationStatus::Converted
                    && $isCurrentVersion,
                'revise' => $request->user()->can('revise', $quotation)
                    && $isCurrentVersion
                    && ! $hasActiveRevisionDraft,
                'email' => $request->user()->can('email', $quotation) && $isCurrentVersion,
                'duplicate' => $request->user()->can('duplicate', $quotation),
                'createCustomer' => $request->user()->hasPermission($quotation->organization_id, 'customers.create'),
            ],
        ]);
    }

    /**
     * Ordered view of a Devis' commercial proposal chain (initial version + every
     * revision) for the HISTORIQUE panel. Empty when the Devis has no revisions.
     *
     * @return list<array<string, mixed>>
     */
    private function revisionHistory(Quotation $quotation): array
    {
        $rootId = $quotation->chainRootId();

        $chain = Quotation::query()
            ->where('organization_id', $quotation->organization_id)
            ->where(fn ($query) => $query->whereKey($rootId)->orWhere('root_quotation_id', $rootId))
            ->orderBy('revision_number')->orderBy('id')
            ->get(['id', 'quotation_number', 'revision_number', 'status', 'issued_at', 'revision_reason']);

        if ($chain->count() < 2) {
            return [];
        }

        // "Version actuelle" = the highest-numbered revision that is official and
        // not superseded. While a revision draft is in progress the previous
        // issued version stays current.
        $currentId = $chain
            ->filter(fn ($q) => $q->status->isOfficial() && $q->status !== QuotationStatus::Superseded)
            ->sortByDesc('revision_number')
            ->first()?->id
            ?? $chain->sortByDesc('revision_number')->first()?->id;

        return $chain->map(fn ($q) => [
            'id' => $q->id,
            'quotation_number' => $q->quotation_number,
            'revision_number' => (int) $q->revision_number,
            'label' => $q->revision_number > 0 ? "Révision {$q->revision_number}" : 'Version initiale',
            'status' => $q->status->value,
            'issued_at' => $q->issued_at?->toIso8601String(),
            'reason' => $q->revision_reason,
            'is_current' => $q->id === $currentId,
        ])->all();
    }

    public function update(UpdateQuotationRequest $request, Quotation $quotation, UpdateQuotationAction $action): RedirectResponse
    {
        $this->authorize('update', $quotation);
        $action->execute($request->user(), $quotation, $request->validated());

        return back();
    }

    public function issue(Request $request, Quotation $quotation, IssueQuotationAction $action): RedirectResponse
    {
        $this->authorize('issue', $quotation);
        $action->execute($request->user(), $quotation);

        return back();
    }

    public function accept(Request $request, Quotation $quotation, DecideQuotationAction $action): RedirectResponse
    {
        $this->authorize('decide', $quotation);
        $action->accept($request->user(), $quotation);

        return back();
    }

    public function reject(Request $request, Quotation $quotation, DecideQuotationAction $action): RedirectResponse
    {
        $this->authorize('decide', $quotation);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $action->reject($request->user(), $quotation, $data['reason'] ?? null);

        return back();
    }

    public function duplicate(Request $request, Quotation $quotation, DuplicateQuotationAction $action): RedirectResponse
    {
        $this->authorize('duplicate', $quotation);
        $copy = $action->execute($request->user(), $quotation);

        return redirect()->route('quotations.show', $copy);
    }

    /**
     * Réviser le devis — open an editable revision Draft from the issued Devis
     * snapshot. The issued version is never mutated; it stays in the historical
     * chain and remains the current document until the revision is issued.
     */
    public function revise(Request $request, Quotation $quotation, StartQuotationRevisionAction $action): RedirectResponse
    {
        $this->authorize('revise', $quotation);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $revision = $action->execute($request->user(), $quotation, $data['reason']);

        return redirect()->route('quotations.show', $revision);
    }

    /**
     * Unified server-side search: catalogue ProductVariants + reusable non-stock
     * library, same organisation only. Never preloads the whole catalogue.
     */
    public function search(Request $request, Quotation $quotation, ProductPriceResolver $priceResolver): JsonResponse
    {
        $this->authorize('update', $quotation);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = trim((string) ($filters['search'] ?? ''));

        $variants = ProductVariant::query()
            ->where('organization_id', $quotation->organization_id)
            ->where('status', CatalogStatus::Active->value)
            ->whereHas('product', fn ($q) => $q->where('status', CatalogStatus::Active->value))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")
                ->orWhere('label', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$search}%")))))
            ->with(['product:id,name,brand_id,default_unit_id', 'product.brand:id,name', 'product.defaultUnit:id,name,symbol', 'taxRate:id,name,rate'])
            ->orderBy('product_id')->orderBy('sku')
            ->limit(15)
            ->get(['id', 'organization_id', 'product_id', 'label', 'sku', 'reference', 'barcode', 'default_sale_price', 'public_price_ttc', 'unit_price_ht', 'tax_rate_id']);

        $defaultTax = $priceResolver->defaultTaxRate($quotation->store, (int) $quotation->organization_id);
        $availability = InventoryBalance::query()
            ->where('organization_id', $quotation->organization_id)
            ->whereIn('product_variant_id', $variants->pluck('id'))
            ->whereHas('warehouse', fn ($q) => $q->where('status', WarehouseStatus::Active->value))
            ->get()
            ->groupBy('product_variant_id')
            ->map(fn ($rows) => $rows->reduce(fn (string $c, InventoryBalance $b) => Decimal::add($c, $b->available), '0.0000'));

        $catalog = $variants->map(function (ProductVariant $v) use ($priceResolver, $defaultTax, $availability) {
            $price = $priceResolver->resolveWith($v, $defaultTax);

            return [
                'kind' => 'catalog',
                'id' => $v->getKey(),
                'product_name' => $v->product->name,
                'variant_name' => $v->label,
                'sku' => $v->sku,
                'reference' => $v->reference,
                'barcode' => $v->barcode,
                'brand' => $v->product->brand?->only(['id', 'name']),
                'unit_label' => $v->product->defaultUnit?->symbol ?? $v->product->defaultUnit?->name,
                'unit_price_excl_tax' => $price['unit_price_ht'] ?? '0.0000',
                'unit_price_incl_tax' => $price['unit_price_ttc'] ?? $v->default_sale_price,
                'tax_rate' => $price['tax_rate_value'],
                'tax_name' => $price['tax_name'],
                'tax_config_missing' => $price['config_missing'],
                'stock_available' => $availability->get($v->getKey(), '0.0000'),
            ];
        });

        $nonStock = NonStockItem::query()
            ->where('organization_id', $quotation->organization_id)
            ->where('status', CatalogStatus::Active->value)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")))
            ->orderBy('name')->limit(15)
            ->get()
            ->map(fn (NonStockItem $i) => [
                'kind' => 'non_stock',
                'id' => $i->getKey(),
                'product_name' => $i->name,
                'variant_name' => null,
                'sku' => null,
                'reference' => $i->reference,
                'unit_label' => $i->unit_label,
                'price_input_mode' => $i->price_input_mode->value,
                'unit_price_excl_tax' => $i->default_price_excl_tax,
                'unit_price_incl_tax' => $i->default_price_incl_tax,
                'tax_rate' => $i->tax_rate,
                'tax_name' => $i->tax_name,
                'usage_count' => $i->usage_count,
            ]);

        return response()->json(['data' => $catalog->concat($nonStock)->values()]);
    }

    private function whatsappMessage(Quotation $quotation): string
    {
        $name = trim((string) ($quotation->customer_name ?: $quotation->customer_company));
        $greeting = $name !== '' ? "Bonjour {$name}," : 'Bonjour,';
        $currency = $quotation->currency_code === 'MAD' ? 'DH' : $quotation->currency_code;
        $formatter = app(\App\Services\DocumentValueFormatter::class);

        return implode("\n", array_filter([
            $greeting,
            "Veuillez trouver notre devis {$quotation->quotation_number}.",
            'Montant : '.$formatter->money($quotation->total_incl_tax).' '.$currency.'.',
            $quotation->valid_until ? 'Valable jusqu’au '.$formatter->date($quotation->valid_until).'.' : null,
        ]));
    }
}
