<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\AssignTransferRequestDriverAction;
use App\Actions\Inventory\CancelTransferRequestAction;
use App\Actions\Inventory\PlanShowroomReplenishmentAction;
use App\Actions\Inventory\PrepareTransferRequestAction;
use App\Actions\Inventory\ReceiveTransferRequestAction;
use App\Actions\Inventory\ShipTransferRequestAction;
use App\Enums\TransferRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\TransferRequest;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
use App\Services\DocumentPdfService;
use App\Services\TransferRequestDocumentRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

class TransferRequestController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): InertiaResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [TransferRequest::class, $organization]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(TransferRequestStatus::class)],
        ]);

        $requests = TransferRequest::query()
            ->where('organization_id', $organization->getKey())
            ->with(['sourceWarehouse:id,name,code', 'destinationWarehouse:id,name,code', 'salesOrder:id,order_number', 'lines'])
            ->withCount('lines')
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('request_number', 'like', "%{$search}%")
                ->orWhereHas('salesOrder', fn ($order) => $order->where('order_number', 'like', "%{$search}%"))
                ->orWhereHas('sourceWarehouse', fn ($warehouse) => $warehouse->where('name', 'like', "%{$search}%"))
                ->orWhereHas('destinationWarehouse', fn ($warehouse) => $warehouse->where('name', 'like', "%{$search}%"))))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (TransferRequest $req) => [
                'id' => $req->id,
                'request_number' => $req->request_number,
                'status' => $req->status->value,
                'source' => $req->sourceWarehouse?->only(['id', 'name', 'code']),
                'destination' => $req->destinationWarehouse?->only(['id', 'name', 'code']),
                'sales_order' => $req->salesOrder?->only(['id', 'order_number']),
                'reasons' => $req->lines->pluck('reason')->map(fn ($r) => $r->value)->unique()->values(),
                'unit_count' => $req->lines->reduce(fn ($c, $l) => $c + (float) $l->quantity, 0.0),
                'created_at' => $req->created_at?->toIso8601String(),
            ]);

        $warehouses = Warehouse::query()
            ->where('organization_id', $organization->getKey())->where('status', 'active')
            ->orderBy('name')->get(['id', 'name', 'code']);

        return Inertia::render('Inventory/TransferRequests/Index', [
            'requests' => $requests,
            'filters' => $filters,
            'warehouses' => $warehouses,
            'can' => [
                'manage' => $request->user()->hasPermission($organization, 'inventory.transfer_requests.manage'),
                'receive' => $request->user()->hasPermission($organization, 'inventory.transfer_requests.receive'),
            ],
        ]);
    }

    public function show(Request $request, TransferRequest $transferRequest): InertiaResponse
    {
        $this->authorize('view', $transferRequest);

        $transferRequest->load([
            'sourceWarehouse:id,name,code', 'destinationWarehouse:id,name,code',
            'salesOrder:id,order_number,status,fulfillment_status',
            'stockTransfer:id,transfer_number,transferred_at',
            'lines.productVariant:id,product_id,label,sku,reference', 'lines.productVariant.product:id,name',
            'requestedBy:id,name', 'preparedBy:id,name', 'shippedBy:id,name', 'receivedBy:id,name', 'driver:id,name',
        ]);

        $canManage = $request->user()->can('manage', $transferRequest);

        return Inertia::render('Inventory/TransferRequests/Show', [
            'request' => [
                ...$transferRequest->only([
                    'id', 'request_number', 'status', 'cancellation_reason',
                    'requested_at', 'prepared_at', 'shipped_at', 'received_at', 'cancelled_at',
                    'driver_name', 'driver_phone', 'vehicle', 'vehicle_registration', 'shipping_note', 'driver_user_id',
                ]),
                'source' => $transferRequest->sourceWarehouse?->only(['id', 'name', 'code']),
                'destination' => $transferRequest->destinationWarehouse?->only(['id', 'name', 'code']),
                'sales_order' => $transferRequest->salesOrder?->only(['id', 'order_number', 'status', 'fulfillment_status']),
                'stock_transfer' => $transferRequest->stockTransfer?->only(['id', 'transfer_number', 'transferred_at']),
                'requested_by' => $transferRequest->requestedBy?->only(['name']),
                'prepared_by' => $transferRequest->preparedBy?->only(['name']),
                'shipped_by' => $transferRequest->shippedBy?->only(['name']),
                'received_by' => $transferRequest->receivedBy?->only(['name']),
                'bon_de_sortie_available' => $transferRequest->bonDeSortieAvailable(),
                'lines' => $transferRequest->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'reason' => $line->reason->value,
                    'reason_label' => $line->reason->label(),
                    'quantity' => $line->quantity,
                    'product_name' => $line->productVariant?->product?->name,
                    'variant_label' => $line->productVariant?->label,
                    'sku' => $line->productVariant?->sku,
                    'reference' => $line->productVariant?->reference,
                ]),
            ],
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
        $action->execute($request->user(), $transferRequest, $request->validate($this->driverRules($transferRequest)));

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
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $action->execute($request->user(), $transferRequest, $data['reason'] ?? null);

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
}
