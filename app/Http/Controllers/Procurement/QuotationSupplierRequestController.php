<?php

namespace App\Http\Controllers\Procurement;

use App\Enums\QuotationStatus;
use App\Enums\QuotationSupplierAvailabilityStatus as Status;
use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\Supplier;
use App\Services\ActiveTenantContext;
use App\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Achats › Demandes fournisseur — an inbox over the canonical
 * `quotation_supplier_availability_requests` (pre-sale availability checks made
 * from Devis). Read-only listing: every mutation goes through the existing
 * QuotationSourcingController routes and their canonical actions.
 *
 * Distinct from Achats › Approvisionnements (Sales Order procurement).
 *
 * Scoped to the active store, exactly like the Devis list: the canonical
 * sourcing actions only accept a Devis of the actor's active store, so a row
 * shown here is always one the operator can act on after switching stores.
 */
class QuotationSupplierRequestController extends Controller
{
    /** Default inbox: unresolved supplier work. */
    private const OPEN = 'open';

    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $store = $context->storeOrFail();
        $user = $request->user();

        // Devis visibility in this store + purchasing visibility of supplier data.
        $this->authorize('viewAny', [Quotation::class, $organization, $store]);
        abort_unless(
            $user->hasPermission($organization, 'procurement.view')
            && $user->hasPermission($organization, 'suppliers.view'),
            403,
        );

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([self::OPEN, 'all', ...array_column(Status::cases(), 'value')])],
            'supplier_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $status = $filters['status'] ?? self::OPEN;
        $filters['status'] = $status;

        $base = QuotationSupplierAvailabilityRequest::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey());

        $requests = (clone $base)
            ->when($status === self::OPEN, fn ($q) => $q->whereIn('status', [Status::Pending->value, Status::Partial->value]))
            ->when(! in_array($status, [self::OPEN, 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['from'] ?? null, fn ($q, string $d) => $q->where('requested_at', '>=', BusinessTime::localToUtc($organization, $d)))
            ->when($filters['to'] ?? null, fn ($q, string $d) => $q->where('requested_at', '<', BusinessTime::localToUtc($organization, $d)->addDay()))
            ->when(trim((string) ($filters['search'] ?? '')), fn ($q, string $s) => $q->where(fn ($q) => $q
                ->whereHas('quotationLine', fn ($l) => $l->where(fn ($l) => $l
                    ->where('product_name', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%")
                    ->orWhere('sku', 'like', "%{$s}%")
                    ->orWhere('reference', 'like', "%{$s}%")))
                ->orWhereHas('quotation', fn ($d) => $d->where(fn ($d) => $d
                    ->where('quotation_number', 'like', "%{$s}%")
                    ->orWhere('customer_name', 'like', "%{$s}%")
                    ->orWhere('customer_company', 'like', "%{$s}%")))
                ->orWhereHas('supplier', fn ($sup) => $sup->where('name', 'like', "%{$s}%"))))
            ->with([
                'quotation:id,organization_id,store_id,quotation_number,status,customer_id,customer_name,customer_company',
                'quotationLine:id,line_type,product_name,variant_name,description,sku,reference',
                'supplier:id,name',
                'requestedBy:id,name',
                'resolvedBy:id,name',
            ])
            ->latest('requested_at')->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (QuotationSupplierAvailabilityRequest $r) => $this->row($r));

        // Supplier filter options: only suppliers that appear in this store's requests (bounded).
        $suppliers = Supplier::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('id', (clone $base)->whereNotNull('supplier_id')->select('supplier_id'))
            ->orderBy('name')->limit(200)->get(['id', 'name']);

        return Inertia::render('Procurement/SupplierRequests/Index', [
            'requests' => $requests,
            'filters' => $filters,
            'suppliers' => $suppliers,
            'can' => [
                // Same permissions the canonical actions enforce server-side.
                'record' => $user->hasPermission($organization, 'procurement.manage'),
                'cancel' => $user->hasPermission($organization, 'quotations.update')
                    || $user->hasPermission($organization, 'procurement.manage'),
                'viewCustomers' => $user->hasPermission($organization, 'customers.view'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(QuotationSupplierAvailabilityRequest $r): array
    {
        $quotation = $r->quotation;
        $line = $r->quotationLine;

        return [
            'id' => $r->id,
            'status' => $r->status->value,
            'quotation' => [
                'id' => $quotation->id,
                'number' => $quotation->quotation_number,
                'is_draft' => $quotation->status === QuotationStatus::Draft,
            ],
            'customer' => [
                'id' => $quotation->customer_id,
                'name' => $quotation->customer_company ?: $quotation->customer_name,
            ],
            'article' => [
                'line_type' => $line?->line_type->value,
                'name' => $line ? ($line->product_name ?: $line->description) : null,
                'variant' => $line?->variant_name,
                'reference' => $line ? ($line->reference ?: $line->sku) : null,
            ],
            'supplier_name' => $r->supplier?->name,
            'requested_quantity' => $r->requested_quantity,
            'confirmed_quantity' => $r->confirmed_quantity,
            'note' => $r->note,
            'requested_by' => $r->requestedBy?->name,
            'requested_at' => $r->requested_at?->toIso8601String(),
            'resolved_by' => $r->resolvedBy?->name,
            'resolved_at' => $r->resolved_at?->toIso8601String(),
        ];
    }
}
