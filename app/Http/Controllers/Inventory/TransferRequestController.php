<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\AssignTransferRequestDriverAction;
use App\Actions\Inventory\CancelTransferRequestAction;
use App\Actions\Inventory\CreateManualTransferRequestAction;
use App\Actions\Inventory\PlanShowroomReplenishmentAction;
use App\Actions\Inventory\PrepareTransferRequestAction;
use App\Actions\Inventory\ReceiveTransferRequestAction;
use App\Actions\Inventory\RestoreTransferRequestAction;
use App\Actions\Inventory\ShipTransferRequestAction;
use App\Actions\Inventory\UpdateTransferRequestLinesAction;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\InventoryBalance;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrderLine;
use App\Models\TransferRequest;
use App\Models\TransferRequestLine;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
use App\Services\DocumentPdfService;
use App\Services\ShowroomReplenishmentPlanner;
use App\Services\TransferRequestDocumentRenderer;
use App\Support\InventoryQuantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

class TransferRequestController extends Controller
{
    /** Max catalogue rows returned to the picker — the list itself scrolls. */
    private const CATALOGUE_LIMIT = 30;

    public function index(Request $request, ActiveTenantContext $context): InertiaResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [TransferRequest::class, $organization]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([...array_map(fn ($s) => $s->value, TransferRequestStatus::cases()), 'open'])],
            'type' => ['nullable', Rule::in(['order', 'replenishment', 'manual'])],
            'priority' => ['nullable', Rule::in(['high', 'normal'])],
            'warehouse_id' => ['nullable', 'integer'],
        ]);
        $organizationId = $organization->getKey();

        $requests = TransferRequest::query()
            ->where('organization_id', $organizationId)
            ->with(['sourceWarehouse:id,name,code', 'destinationWarehouse:id,name,code', 'salesOrder:id,order_number', 'lines:id,transfer_request_id,reason,quantity'])
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('request_number', 'like', "%{$search}%")
                ->orWhereHas('salesOrder', fn ($order) => $order->where('order_number', 'like', "%{$search}%"))
                ->orWhereHas('sourceWarehouse', fn ($warehouse) => $warehouse->where('name', 'like', "%{$search}%"))
                ->orWhereHas('destinationWarehouse', fn ($warehouse) => $warehouse->where('name', 'like', "%{$search}%"))))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $status === 'open'
                ? $query->whereIn('status', ShowroomReplenishmentPlanner::ACTIVE_STATUSES)
                : $query->where('status', $status))
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where(fn ($query) => $query
                ->where('source_warehouse_id', (int) $id)->orWhere('destination_warehouse_id', (int) $id)))
            ->when($filters['type'] ?? null, fn ($query, string $type) => $this->scopeType($query, $type))
            ->when($filters['priority'] ?? null, fn ($query, string $priority) => $priority === 'high'
                ? $query->whereNotNull('sales_order_id')
                : $query->whereNull('sales_order_id'))
            // Open customer-order requests first, then newest.
            ->orderByRaw('case when sales_order_id is not null and status in (?, ?, ?) then 0 else 1 end', ShowroomReplenishmentPlanner::ACTIVE_STATUSES)
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (TransferRequest $req) => [
                'id' => $req->id,
                'request_number' => $req->request_number,
                'status' => $req->status->value,
                'type' => $this->typeOf($req),
                'priority' => $req->sales_order_id !== null ? 'high' : 'normal',
                'source' => $req->sourceWarehouse?->only(['id', 'name', 'code']),
                'destination' => $req->destinationWarehouse?->only(['id', 'name', 'code']),
                'sales_order' => $req->salesOrder?->only(['id', 'order_number']),
                'reasons' => $req->lines->pluck('reason')->map(fn ($r) => $r->value)->unique()->values(),
                'product_count' => $req->lines->count(),
                'unit_count' => $req->lines->reduce(fn ($c, $l) => $c + (float) $l->quantity, 0.0),
                'restored' => $req->restored_at !== null,
                'created_at' => $req->created_at?->toIso8601String(),
            ]);

        // One grouped query for the overview counters (open work only).
        $counts = TransferRequest::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', ShowroomReplenishmentPlanner::ACTIVE_STATUSES)
            ->selectRaw('status, case when sales_order_id is null then 0 else 1 end as is_order, count(*) as aggregate')
            ->groupBy('status', 'is_order')
            ->get();
        $summary = [
            'requested' => (int) $counts->where('status', TransferRequestStatus::Requested)->sum('aggregate'),
            'preparing' => (int) $counts->where('status', TransferRequestStatus::Preparing)->sum('aggregate'),
            'shipped' => (int) $counts->where('status', TransferRequestStatus::Shipped)->sum('aggregate'),
            'customer_open' => (int) $counts->filter(fn ($row) => (int) $row->is_order === 1)->sum('aggregate'),
        ];

        $warehouses = Warehouse::query()
            ->where('organization_id', $organization->getKey())->where('status', 'active')
            ->orderBy('name')->get(['id', 'name', 'code']);

        return Inertia::render('Inventory/TransferRequests/Index', [
            'requests' => $requests,
            'filters' => $filters,
            'summary' => $summary,
            'warehouses' => $warehouses,
            'can' => [
                'manage' => $request->user()->hasPermission($organization, 'inventory.transfer_requests.manage'),
                'receive' => $request->user()->hasPermission($organization, 'inventory.transfer_requests.receive'),
            ],
        ]);
    }

    /** "Créer une demande" — manual request form (optionally pre-filled from a cancelled request). */
    public function create(Request $request, ActiveTenantContext $context, ShowroomReplenishmentPlanner $planner): InertiaResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'inventory.transfer_requests.manage'), 403);

        $filters = $this->pickerFilters($request, $organization);

        $prefill = null;
        if ($filters['from'] ?? null) {
            $from = TransferRequest::query()->where('organization_id', $organization->getKey())->whereKey($filters['from'])
                ->with('lines')->first();
            if ($from) {
                $prefill = [
                    'request_number' => $from->request_number,
                    'source_warehouse_id' => $from->source_warehouse_id,
                    'destination_warehouse_id' => $from->destination_warehouse_id,
                    'lines' => $from->lines
                        ->reject(fn ($l) => $l->reason === TransferRequestReason::OrderFulfillment)
                        ->groupBy('product_variant_id')
                        ->map(fn ($lines, $variantId) => [
                            'product_variant_id' => (int) $variantId,
                            'quantity' => $lines->reduce(fn ($c, $l) => InventoryQuantity::add($c, $l->quantity), InventoryQuantity::ZERO),
                        ])->values()->all(),
                ];
                $filters['source_warehouse_id'] ??= $from->source_warehouse_id;
                $filters['destination_warehouse_id'] ??= $from->destination_warehouse_id;
                $filters['selected'] = collect($prefill['lines'])->pluck('product_variant_id')->implode(',');
            }
        }

        return Inertia::render('Inventory/TransferRequests/Form', [
            'mode' => 'create',
            'request' => null,
            'prefill' => $prefill,
            'filters' => $filters,
            'warehouses' => $this->activeWarehouses($organization),
            'searchResults' => fn () => $this->catalogue($planner, $organization, $filters, null),
            'selectedStock' => fn () => $this->selectedStock($planner, $organization, $filters, null),
            'operationId' => (string) str()->uuid(),
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, CreateManualTransferRequestAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'inventory.transfer_requests.manage'), 403);

        $data = $request->validate([
            'source_warehouse_id' => ['required', 'integer'],
            'destination_warehouse_id' => ['required', 'integer', 'different:source_warehouse_id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'operation_id' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_variant_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
        ], [
            'destination_warehouse_id.different' => 'La destination doit être différente de la source.',
            'lines.required' => 'Ajoutez au moins un article.',
        ]);

        $created = $action->execute(
            $request->user(), $organization,
            (int) $data['source_warehouse_id'], (int) $data['destination_warehouse_id'],
            $data['lines'], $data['note'] ?? null, $data['operation_id'] ?? null,
        );

        return redirect()->route('inventory.transfer-requests.show', $created)
            ->with('success', "Demande {$created->request_number} créée.");
    }

    public function edit(Request $request, TransferRequest $transferRequest, ShowroomReplenishmentPlanner $planner): InertiaResponse|RedirectResponse
    {
        $this->authorize('manage', $transferRequest);
        if ($transferRequest->status !== TransferRequestStatus::Requested) {
            return redirect()->route('inventory.transfer-requests.show', $transferRequest)
                ->with('error', 'Seule une demande « demandée » peut être modifiée.');
        }

        $organization = $transferRequest->organization;
        $filters = $this->pickerFilters($request, $organization);
        $filters['source_warehouse_id'] = $transferRequest->source_warehouse_id;
        $filters['destination_warehouse_id'] = $transferRequest->destination_warehouse_id;

        $transferRequest->load(['sourceWarehouse:id,name,code', 'destinationWarehouse:id,name,code', 'salesOrder:id,order_number',
            'lines.productVariant:id,product_id,label,sku,reference', 'lines.productVariant.product:id,name,brand_id', 'lines.productVariant.product.brand:id,name']);
        $stock = $this->stockFor($planner, $organization, $transferRequest->source_warehouse_id, $transferRequest->destination_warehouse_id,
            $transferRequest->lines->pluck('product_variant_id')->all(), $transferRequest->getKey());

        return Inertia::render('Inventory/TransferRequests/Form', [
            'mode' => 'edit',
            'request' => [
                'id' => $transferRequest->id,
                'request_number' => $transferRequest->request_number,
                'lock_version' => (int) $transferRequest->lock_version,
                'note' => $transferRequest->note,
                'source' => $transferRequest->sourceWarehouse?->only(['id', 'name', 'code']),
                'destination' => $transferRequest->destinationWarehouse?->only(['id', 'name', 'code']),
                'sales_order' => $transferRequest->salesOrder?->only(['id', 'order_number']),
                'lines' => $transferRequest->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'product_variant_id' => (int) $line->product_variant_id,
                    'reason' => $line->reason->value,
                    'protected' => $line->reason === TransferRequestReason::OrderFulfillment,
                    'quantity' => $line->quantity,
                    ...$this->variantPayload($line->productVariant),
                    'stock' => $stock[(int) $line->product_variant_id] ?? null,
                ])->values(),
            ],
            'prefill' => null,
            'filters' => $filters,
            'warehouses' => $this->activeWarehouses($organization),
            'searchResults' => fn () => $this->catalogue($planner, $organization, $filters, $transferRequest->getKey()),
            'selectedStock' => fn () => $this->selectedStock($planner, $organization, $filters, $transferRequest->getKey()),
            'operationId' => null,
        ]);
    }

    public function updateLines(Request $request, TransferRequest $transferRequest, UpdateTransferRequestLinesAction $action): RedirectResponse
    {
        $this->authorize('manage', $transferRequest);
        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['present', 'array', 'max:200'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.product_variant_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
        ]);

        $action->execute($request->user(), $transferRequest, (int) $data['lock_version'], $data['lines'], $data['note'] ?? '');

        return redirect()->route('inventory.transfer-requests.show', $transferRequest)->with('success', 'Demande de transfert mise à jour.');
    }

    public function restore(Request $request, TransferRequest $transferRequest, RestoreTransferRequestAction $action): RedirectResponse
    {
        $this->authorize('manage', $transferRequest);
        $action->execute($request->user(), $transferRequest);

        return back()->with('success', 'Demande de transfert rétablie. Elle doit être préparée à nouveau.');
    }

    public function show(Request $request, TransferRequest $transferRequest, RestoreTransferRequestAction $restore, ShowroomReplenishmentPlanner $planner): InertiaResponse
    {
        $this->authorize('view', $transferRequest);

        $transferRequest->load([
            'sourceWarehouse:id,name,code', 'destinationWarehouse:id,name,code',
            'salesOrder:id,order_number,status,fulfillment_status,customer_id', 'salesOrder.customer:id,display_name',
            'stockTransfer:id,transfer_number,transferred_at',
            'lines.productVariant:id,product_id,label,sku,reference', 'lines.productVariant.product:id,name',
            'requestedBy:id,name', 'preparedBy:id,name', 'shippedBy:id,name', 'receivedBy:id,name', 'driver:id,name', 'restoredBy:id,name',
        ]);

        $canManage = $request->user()->can('manage', $transferRequest);

        // Source stock is only useful while the request can still be edited or reviewed.
        $stock = $canManage && in_array($transferRequest->status, [TransferRequestStatus::Requested, TransferRequestStatus::Preparing], true)
            ? $planner->transferableMap($transferRequest->organization_id, $transferRequest->source_warehouse_id,
                $transferRequest->lines->pluck('product_variant_id')->map(fn ($id) => (int) $id)->all(), $transferRequest->getKey())
            : [];

        $breakdown = $this->breakdown($planner, $transferRequest, $stock);

        return Inertia::render('Inventory/TransferRequests/Show', [
            'breakdown' => $breakdown,
            'history' => $this->history($transferRequest),
            'request' => [
                ...$transferRequest->only([
                    'id', 'request_number', 'status', 'cancellation_reason', 'note',
                    'requested_at', 'prepared_at', 'shipped_at', 'received_at', 'cancelled_at', 'restored_at',
                    'driver_name', 'driver_phone', 'vehicle', 'vehicle_registration', 'shipping_note', 'driver_user_id',
                ]),
                'lock_version' => (int) $transferRequest->lock_version,
                'type' => $this->typeOf($transferRequest),
                'priority' => $transferRequest->sales_order_id !== null ? 'high' : 'normal',
                'created_at' => $transferRequest->created_at?->toIso8601String(),
                'source' => $transferRequest->sourceWarehouse?->only(['id', 'name', 'code']),
                'destination' => $transferRequest->destinationWarehouse?->only(['id', 'name', 'code']),
                'sales_order' => $transferRequest->salesOrder ? [
                    ...$transferRequest->salesOrder->only(['id', 'order_number', 'fulfillment_status']),
                    'status' => $transferRequest->salesOrder->status?->value ?? $transferRequest->salesOrder->status,
                    'customer' => $transferRequest->salesOrder->customer?->display_name,
                ] : null,
                'stock_transfer' => $transferRequest->stockTransfer?->only(['id', 'transfer_number', 'transferred_at']),
                'requested_by' => $transferRequest->requestedBy?->only(['name']),
                'prepared_by' => $transferRequest->preparedBy?->only(['name']),
                'shipped_by' => $transferRequest->shippedBy?->only(['name']),
                'received_by' => $transferRequest->receivedBy?->only(['name']),
                'restored_by' => $transferRequest->restoredBy?->only(['name']),
                'bon_de_sortie_available' => $transferRequest->bonDeSortieAvailable(),
                'lines' => $transferRequest->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'reason' => $line->reason->value,
                    'reason_label' => $line->reason->label(),
                    'protected' => $line->reason === TransferRequestReason::OrderFulfillment,
                    'quantity' => $line->quantity,
                    'requested_quantity' => $line->requested_quantity,
                    'reallocated_quantity' => $line->reallocated_quantity,
                    'product_variant_id' => (int) $line->product_variant_id,
                    'product_name' => $line->productVariant?->product?->name,
                    'variant_label' => $line->productVariant?->label,
                    'sku' => $line->productVariant?->sku,
                    'reference' => $line->productVariant?->reference,
                    'transferable' => $stock[(int) $line->product_variant_id]['transferable'] ?? null,
                ]),
            ],
            'restore' => $canManage && $transferRequest->status === TransferRequestStatus::Cancelled
                ? $restore->assess($transferRequest)
                : null,
            // Bounded list for the "Chauffeur / Livreur" picker — the driver need
            // not be an ERP user, so a free-text name is also accepted.
            'members' => $canManage
                ? $transferRequest->organization->memberships()
                    ->where('status', 'active')
                    ->with('user:id,name,email')
                    ->limit(200)->get()
                    ->map(fn ($m) => ['id' => $m->user_id, 'name' => $m->user?->name, 'email' => $m->user?->email])
                    ->filter(fn ($m) => $m['name'] !== null)->values()
                : [],
            'can' => [
                'manage' => $canManage,
                'receive' => $request->user()->can('receive', $transferRequest),
            ],
        ]);
    }

    public function prepare(Request $request, TransferRequest $transferRequest, PrepareTransferRequestAction $action): RedirectResponse
    {
        $this->authorize('manage', $transferRequest);
        $action->execute($request->user(), $transferRequest);

        return back()->with('success', 'Demande de transfert préparée.');
    }

    public function ship(Request $request, TransferRequest $transferRequest, ShipTransferRequestAction $action): RedirectResponse
    {
        $this->authorize('manage', $transferRequest);
        $shipment = $request->validate($this->driverRules($transferRequest));
        $review = $request->validate([
            'lock_version' => ['nullable', 'integer', 'min:0'],
            'lines' => ['nullable', 'array', 'max:500'],
            'lines.*' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
        ]);

        $action->execute(
            $request->user(), $transferRequest, $shipment,
            isset($review['lines']) ? $review['lines'] : null,
            isset($review['lock_version']) ? (int) $review['lock_version'] : null,
        );

        return back()->with('success', 'Demande de transfert expédiée.');
    }

    public function assignDriver(Request $request, TransferRequest $transferRequest, AssignTransferRequestDriverAction $action): RedirectResponse
    {
        $this->authorize('manage', $transferRequest);
        $action->execute($request->user(), $transferRequest, $request->validate($this->driverRules($transferRequest)));

        return back()->with('success', 'Chauffeur enregistré.');
    }

    /** @return array<string, mixed> */
    private function driverRules(TransferRequest $transferRequest): array
    {
        return [
            'driver_user_id' => ['nullable', 'integer', Rule::exists('organization_memberships', 'user_id')
                ->where(fn ($query) => $query->where('organization_id', $transferRequest->organization_id)->where('status', 'active'))],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'driver_phone' => ['nullable', 'string', 'max:64'],
            'vehicle' => ['nullable', 'string', 'max:255'],
            'vehicle_registration' => ['nullable', 'string', 'max:64'],
            'shipping_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** The optional printable Bon de sortie — no stock effect, reprintable. */
    public function bon(Request $request, TransferRequest $transferRequest, DocumentPdfService $documents): Response
    {
        return $this->streamBon($request, $transferRequest, $documents, download: false);
    }

    public function bonDownload(Request $request, TransferRequest $transferRequest, DocumentPdfService $documents): Response
    {
        return $this->streamBon($request, $transferRequest, $documents, download: true);
    }

    public function bonPreview(Request $request, TransferRequest $transferRequest, TransferRequestDocumentRenderer $renderer): Response
    {
        $this->authorize('view', $transferRequest);
        abort_unless($transferRequest->bonDeSortieAvailable(), 404);

        return response($renderer->html($transferRequest), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function streamBon(Request $request, TransferRequest $transferRequest, DocumentPdfService $documents, bool $download): Response
    {
        $this->authorize('view', $transferRequest);
        abort_unless($transferRequest->bonDeSortieAvailable(), 404);

        try {
            $document = $documents->transferRequestBonDeSortie($transferRequest);
        } catch (Throwable $exception) {
            report($exception);
            abort(503, 'La génération du document est momentanément indisponible.');
        }

        return response($document['bytes'], 200, [
            'Content-Type' => $document['mime'],
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$document['filename'].'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function receive(Request $request, TransferRequest $transferRequest, ReceiveTransferRequestAction $action): RedirectResponse
    {
        $this->authorize('receive', $transferRequest);
        $action->execute($request->user(), $transferRequest);

        return back()->with('success', 'Demande de transfert réceptionnée.');
    }

    public function cancel(Request $request, TransferRequest $transferRequest, CancelTransferRequestAction $action): RedirectResponse
    {
        $this->authorize('manage', $transferRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']], [
            'reason.required' => 'Le motif d’annulation est obligatoire.',
            'reason.min' => 'Précisez le motif d’annulation (3 caractères minimum).',
        ]);
        $action->execute($request->user(), $transferRequest, $data['reason']);

        return back()->with('success', 'Demande de transfert annulée.');
    }

    public function replenish(Request $request, ActiveTenantContext $context, PlanShowroomReplenishmentAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'inventory.transfer_requests.manage'), 403);

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query
                ->where('organization_id', $organization->getKey())->where('status', 'active'))],
        ]);
        $warehouse = Warehouse::query()->where('organization_id', $organization->getKey())->whereKey($data['warehouse_id'])->firstOrFail();

        $created = $action->execute($request->user(), $organization, $warehouse);

        return back()->with('success', count($created) === 0
            ? 'Aucun réassort nécessaire.'
            : count($created).' demande(s) de réassort créée(s) ou complétée(s).');
    }

    // --- list helpers ---------------------------------------------------------

    /** customer order → `order`; only replenishment lines → `replenishment`; otherwise `manual`. */
    private function typeOf(TransferRequest $request): string
    {
        if ($request->sales_order_id !== null) {
            return 'order';
        }
        $lines = $request->relationLoaded('lines') ? $request->lines : $request->lines()->get(['reason']);

        return $lines->isNotEmpty() && $lines->every(fn ($l) => $l->reason === TransferRequestReason::MinimumReplenishment)
            ? 'replenishment'
            : 'manual';
    }

    private function scopeType($query, string $type)
    {
        return match ($type) {
            'order' => $query->whereNotNull('sales_order_id'),
            'replenishment' => $query->whereNull('sales_order_id')
                ->whereHas('lines')
                ->whereDoesntHave('lines', fn ($q) => $q->where('reason', '!=', TransferRequestReason::MinimumReplenishment->value)),
            default => $query->whereNull('sales_order_id')
                ->where(fn ($q) => $q->whereDoesntHave('lines')
                    ->orWhereHas('lines', fn ($l) => $l->where('reason', '!=', TransferRequestReason::MinimumReplenishment->value))),
        };
    }

    // --- detail helpers -------------------------------------------------------

    /**
     * Per-product quantity breakdown a warehouse employee can read without
     * doing arithmetic: what the customer needs, what the destination already
     * covers, what this request carries for the customer and to restore the
     * minimum (incl. what was moved from a general request), what else is on
     * its way, source stock and the destination stock projected once the
     * customer is served.
     *
     * @param  array<int, array<string, string>>  $sourceStock  transferableMap excluding this request
     * @return list<array<string, mixed>>
     */
    private function breakdown(ShowroomReplenishmentPlanner $planner, TransferRequest $request, array $sourceStock): array
    {
        $orgId = (int) $request->organization_id;
        $destId = (int) $request->destination_warehouse_id;
        $variantIds = $request->lines->pluck('product_variant_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($variantIds === []) {
            return [];
        }
        $active = in_array($request->status->value, ShowroomReplenishmentPlanner::ACTIVE_STATUSES, true);

        $minimums = $planner->isEnabled($orgId, $destId) ? $planner->minimumsMap($orgId, $destId, $variantIds) : [];
        $otherIncoming = $planner->incomingFreeMap($orgId, $destId, $variantIds, $request->getKey());
        $destination = InventoryBalance::query()->where('organization_id', $orgId)->where('warehouse_id', $destId)
            ->whereIn('product_variant_id', $variantIds)->get(['product_variant_id', 'on_hand', 'reserved'])
            ->keyBy(fn ($b) => (int) $b->product_variant_id);
        $sourceBalances = InventoryBalance::query()->where('organization_id', $orgId)->where('warehouse_id', $request->source_warehouse_id)
            ->whereIn('product_variant_id', $variantIds)->get(['product_variant_id', 'on_hand', 'reserved'])
            ->keyBy(fn ($b) => (int) $b->product_variant_id);

        // Customer side: ordered quantity and what the order routes through its
        // OTHER live requests (another depot), so "covered at destination" is
        // stable before and after receipt.
        $ordered = [];
        $otherOrderRouted = [];
        if ($request->sales_order_id !== null) {
            SalesOrderLine::query()->where('organization_id', $orgId)->where('sales_order_id', $request->sales_order_id)
                ->whereIn('product_variant_id', $variantIds)->get(['product_variant_id', 'quantity'])
                ->each(function ($line) use (&$ordered) {
                    $id = (int) $line->product_variant_id;
                    $ordered[$id] = InventoryQuantity::add($ordered[$id] ?? InventoryQuantity::ZERO, $line->quantity);
                });
            TransferRequestLine::query()
                ->join('transfer_requests as tr', 'tr.id', '=', 'transfer_request_lines.transfer_request_id')
                ->where('tr.organization_id', $orgId)->where('tr.sales_order_id', $request->sales_order_id)
                ->where('tr.id', '!=', $request->getKey())->where('tr.status', '!=', TransferRequestStatus::Cancelled->value)
                ->where('transfer_request_lines.reason', TransferRequestReason::OrderFulfillment->value)
                ->whereIn('transfer_request_lines.product_variant_id', $variantIds)
                ->get(['transfer_request_lines.product_variant_id', 'transfer_request_lines.quantity'])
                ->each(function ($line) use (&$otherOrderRouted) {
                    $id = (int) $line->product_variant_id;
                    $otherOrderRouted[$id] = InventoryQuantity::add($otherOrderRouted[$id] ?? InventoryQuantity::ZERO, $line->quantity);
                });
        }

        $zero = InventoryQuantity::ZERO;
        $max0 = fn (string $q) => InventoryQuantity::compare($q, $zero) > 0 ? $q : $zero;
        $min = fn (string $a, string $b) => InventoryQuantity::compare($a, $b) < 0 ? $a : $b;

        // Which general requests each product's replenishment was moved from
        // (bounded; the full trail stays in the history panel).
        $reallocatedFrom = [];
        AuditLog::query()
            ->where('organization_id', $orgId)
            ->where('auditable_type', $request->getMorphClass())
            ->where('auditable_id', $request->getKey())
            ->where('event', 'transfer_request.replenishment_received_from_general')
            ->latest('id')->limit(50)->get(['new_values'])
            ->each(function ($log) use (&$reallocatedFrom) {
                $variant = (int) ($log->new_values['product_variant_id'] ?? 0);
                $number = $log->new_values['request_number'] ?? null;
                if ($variant && $number && ! in_array($number, $reallocatedFrom[$variant] ?? [], true)) {
                    $reallocatedFrom[$variant][] = $number;
                }
            });

        $out = [];
        foreach ($request->lines->groupBy(fn ($l) => (int) $l->product_variant_id) as $variantId => $lines) {
            $sum = fn (TransferRequestReason $reason) => $lines->filter(fn ($l) => $l->reason === $reason)
                ->reduce(fn (string $c, $l) => InventoryQuantity::add($c, $l->quantity), $zero);
            $forCustomer = $sum(TransferRequestReason::OrderFulfillment);
            $replenishment = $sum(TransferRequestReason::MinimumReplenishment);
            $manual = $sum(TransferRequestReason::Manual);
            $reallocated = $lines->filter(fn ($l) => $l->reason === TransferRequestReason::MinimumReplenishment)
                ->reduce(fn (string $c, $l) => InventoryQuantity::add($c, (string) ($l->reallocated_quantity ?? $zero)), $zero);
            $free = InventoryQuantity::add($replenishment, $manual);

            $customerQty = $ordered[$variantId] ?? null;
            $coveredAtDestination = $customerQty !== null
                ? $max0(InventoryQuantity::subtract(InventoryQuantity::subtract($customerQty, $forCustomer), $otherOrderRouted[$variantId] ?? $zero))
                : null;

            $dest = $destination->get($variantId);
            $destOnHand = $dest ? InventoryQuantity::normalize((string) $dest->on_hand) : $zero;
            $destAvailable = $dest ? $max0(InventoryQuantity::subtract($dest->on_hand, $dest->reserved)) : $zero;
            $incomingElsewhere = $otherIncoming[$variantId] ?? $zero;

            $src = $sourceBalances->get($variantId);
            $transferable = $sourceStock[$variantId]['transferable'] ?? null;
            $shortage = $transferable !== null ? $max0(InventoryQuantity::subtract($free, $transferable)) : $zero;
            $projected = $active ? InventoryQuantity::add($destAvailable, InventoryQuantity::add($incomingElsewhere, $free)) : null;
            $minimum = $minimums[$variantId] ?? null;

            $out[] = [
                'product_variant_id' => $variantId,
                'product_name' => $lines->first()->productVariant?->product?->name,
                'variant_label' => $lines->first()->productVariant?->label,
                'sku' => $lines->first()->productVariant?->sku,
                'reference' => $lines->first()->productVariant?->reference,
                'customer_quantity' => $customerQty,
                'covered_at_destination' => $coveredAtDestination,
                'for_customer' => $forCustomer,
                'for_customer_other_sources' => $otherOrderRouted[$variantId] ?? $zero,
                'replenishment' => $replenishment,
                'reallocated' => $min($reallocated, $replenishment),
                'reallocated_from' => $reallocatedFrom[$variantId] ?? [],
                'manual' => $manual,
                'total' => InventoryQuantity::add($forCustomer, $free),
                'other_active_incoming' => $incomingElsewhere,
                'minimum' => $minimum,
                'destination_on_hand' => $destOnHand,
                'destination_available' => $destAvailable,
                // Once the customer is served: free destination stock (the
                // customer's units are reserved, so already excluded) plus every
                // free unit still on its way, including this request's.
                'projected_after_fulfillment' => $projected,
                // Units the minimum will still miss after everything on its way
                // arrives — shown, never fabricated.
                'minimum_shortage' => $projected !== null && $minimum !== null && InventoryQuantity::compare($minimum, $zero) > 0
                    ? $max0(InventoryQuantity::subtract($minimum, $projected))
                    : null,
                'source_on_hand' => $src ? InventoryQuantity::normalize((string) $src->on_hand) : $zero,
                'source_reserved' => $src ? InventoryQuantity::normalize((string) $src->reserved) : $zero,
                'source_transferable' => $transferable,
                'shortage' => $shortage,
            ];
        }

        return $out;
    }

    /** Last 40 audit events of this request, newest first (bounded). */
    private function history(TransferRequest $request): array
    {
        return AuditLog::query()
            ->where('organization_id', $request->organization_id)
            ->where('auditable_type', $request->getMorphClass())
            ->where('auditable_id', $request->getKey())
            ->with('actor:id,name')
            ->latest('id')
            ->limit(40)
            ->get(['id', 'event', 'actor_id', 'old_values', 'new_values', 'created_at'])
            ->map(fn ($log) => [
                'id' => $log->id,
                'event' => $log->event,
                'actor' => $log->actor?->name,
                'at' => $log->created_at?->toIso8601String(),
                'old' => $log->old_values,
                'new' => $log->new_values,
            ])->all();
    }

    // --- request form helpers ----------------------------------------------

    /** @return array<string, mixed> */
    private function pickerFilters(Request $request, Organization $organization): array
    {
        $activeWarehouse = Rule::exists('warehouses', 'id')->where(fn ($query) => $query
            ->where('organization_id', $organization->getKey())->where('status', 'active'));

        $filters = $request->validate([
            'source_warehouse_id' => ['nullable', 'integer', $activeWarehouse],
            'destination_warehouse_id' => ['nullable', 'integer', $activeWarehouse],
            'search' => ['nullable', 'string', 'max:255'],
            'selected' => ['nullable', 'string', 'max:4000', 'regex:/^[0-9,]*$/'],
            'from' => ['nullable', 'integer'],
        ]);

        foreach (['source_warehouse_id', 'destination_warehouse_id', 'from'] as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        return $filters;
    }

    private function activeWarehouses(Organization $organization)
    {
        return Warehouse::query()
            ->where('organization_id', $organization->getKey())->where('status', 'active')
            ->orderBy('name')->get(['id', 'name', 'code']);
    }

    /**
     * Bounded product search at the source: only variants with available
     * stock there, each with its transferable quantity (available minus what
     * other active requests already promise) and the destination's stock.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function catalogue(ShowroomReplenishmentPlanner $planner, Organization $organization, array $filters, ?int $excludeRequestId): array
    {
        $sourceId = $filters['source_warehouse_id'] ?? null;
        if (! $sourceId) {
            return [];
        }
        $search = trim((string) ($filters['search'] ?? ''));

        $variants = ProductVariant::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', 'active')
            ->with(['product:id,name,brand_id', 'product.brand:id,name'])
            ->whereHas('inventoryBalances', fn ($query) => $query
                ->where('organization_id', $organization->getKey())
                ->where('warehouse_id', $sourceId)
                ->whereRaw('(on_hand - reserved) > 0'))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")
                ->orWhere('label', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($product) => $product
                    ->where('name', 'like', "%{$search}%")
                    ->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', "%{$search}%")))))
            ->orderBy('sku')
            ->limit(self::CATALOGUE_LIMIT)
            ->get(['id', 'product_id', 'label', 'sku', 'reference', 'barcode']);

        $stock = $this->stockFor($planner, $organization, (int) $sourceId, $filters['destination_warehouse_id'] ?? null, $variants->modelKeys(), $excludeRequestId);

        return $variants->map(fn (ProductVariant $variant) => [
            'product_variant_id' => $variant->getKey(),
            ...$this->variantPayload($variant),
            'stock' => $stock[$variant->getKey()] ?? null,
        ])->values()->all();
    }

    /**
     * Fresh stock for the lines already in the picker (after a source change
     * or simply to show current numbers next to each selected line).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, string>>
     */
    private function selectedStock(ShowroomReplenishmentPlanner $planner, Organization $organization, array $filters, ?int $excludeRequestId): array
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($filters['selected'] ?? '')))));
        if (! ($filters['source_warehouse_id'] ?? null) || $ids === []) {
            return [];
        }
        $ids = ProductVariant::query()->where('organization_id', $organization->getKey())->whereIn('id', array_slice($ids, 0, 200))->pluck('id')->all();

        $variants = ProductVariant::query()->whereIn('id', $ids)
            ->with(['product:id,name,brand_id', 'product.brand:id,name'])
            ->get(['id', 'product_id', 'label', 'sku', 'reference', 'barcode'])->keyBy('id');
        $stock = $this->stockFor($planner, $organization, (int) $filters['source_warehouse_id'], $filters['destination_warehouse_id'] ?? null, $ids, $excludeRequestId);

        $out = [];
        foreach ($variants as $id => $variant) {
            $out[$id] = ['product_variant_id' => $id, ...$this->variantPayload($variant), 'stock' => $stock[$id] ?? null];
        }

        return $out;
    }

    /**
     * @param  list<int|string>  $variantIds
     * @return array<int, array<string, string>>
     */
    private function stockFor(ShowroomReplenishmentPlanner $planner, Organization $organization, int $sourceId, ?int $destinationId, array $variantIds, ?int $excludeRequestId): array
    {
        $variantIds = array_map('intval', $variantIds);
        $source = $planner->transferableMap($organization->getKey(), $sourceId, $variantIds, $excludeRequestId);

        $destination = $destinationId
            ? InventoryBalance::query()->where('organization_id', $organization->getKey())->where('warehouse_id', $destinationId)
                ->whereIn('product_variant_id', $variantIds ?: [0])->pluck('on_hand', 'product_variant_id')
            : collect();

        $out = [];
        foreach ($source as $variantId => $row) {
            $out[$variantId] = [
                ...$row,
                'destination_on_hand' => $destination->has($variantId) ? InventoryQuantity::normalize((string) $destination[$variantId]) : InventoryQuantity::ZERO,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function variantPayload(?ProductVariant $variant): array
    {
        return [
            'product_name' => $variant?->product?->name,
            'label' => $variant?->label,
            'sku' => $variant?->sku,
            'reference' => $variant?->reference,
            'brand' => $variant?->product?->brand?->name,
        ];
    }
}
