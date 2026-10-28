<?php

namespace App\Http\Controllers\Procurement;

use App\Actions\Procurement\CancelPurchaseOrderAction;
use App\Actions\Procurement\CreatePurchaseOrderFromProcurementRequirementsAction;
use App\Actions\Procurement\ReceiveProcurementAction;
use App\Enums\PurchaseOrderStatus;
use App\Enums\WarehouseStatus;
use App\Http\Controllers\Controller;
use App\Models\ProcurementReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrder;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\BusinessTime;
use App\Support\Decimal;
use App\Services\ActiveTenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Supplier purchase orders (BCF): list, detail, creation from a Sales Order's requirements, cancellation. */
class PurchaseOrderController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [PurchaseOrder::class, $organization]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(PurchaseOrderStatus::class)],
        ]);

        $orders = PurchaseOrder::query()
            ->where('organization_id', $organization->getKey())
            ->with(['supplier:id,name', 'store:id,name'])
            ->withCount('lines')
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('purchase_order_number', 'like', '%'.addcslashes($search, '\\%_').'%')
                ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', '%'.addcslashes($search, '\\%_').'%'))))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PurchaseOrder $po) => [
                'id' => $po->id,
                'purchase_order_number' => $po->purchase_order_number,
                'status' => $po->status->value,
                'supplier' => $po->supplier?->only(['id', 'name']),
                'store' => $po->store?->only(['id', 'name']),
                'order_date' => $po->order_date?->toDateString(),
                'expected_delivery_date' => $po->expected_delivery_date?->toDateString(),
                'line_count' => $po->lines_count,
            ]);

        return Inertia::render('Procurement/PurchaseOrders/Index', ['purchaseOrders' => $orders, 'filters' => $filters]);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $this->authorize('view', $purchaseOrder);
        $purchaseOrder->load([
            'supplier:id,name,phone,email', 'store:id,name', 'createdBy:id,name', 'cancelledBy:id,name',
            'lines.salesOrder:id,order_number', 'lines.salesOrderLine:id,product_name,variant_name,quantity', 'lines.productVariant:id,sku,reference',
            'lines.procurement:id,procurement_number,status,quantity,received_quantity,source_quotation_id,source_supplier_request_id',
            'lines.procurement.sourceQuotation:id,quotation_number',
            'lines.receipts.warehouse:id,name,code', 'lines.receipts.receivedBy:id,name',
        ]);
        $evidence = QuotationSupplierAvailabilityRequest::query()->where('organization_id', $purchaseOrder->organization_id)
            ->whereKey($purchaseOrder->lines->pluck('procurement.source_supplier_request_id')->filter()->unique()->all())
            ->with('supplier:id,name')->get(['id', 'supplier_id', 'status', 'confirmed_quantity', 'resolved_at'])->keyBy('id');
        $canReceive = $purchaseOrder->status->isReceivable() && $request->user()->can('receive', $purchaseOrder);

        return Inertia::render('Procurement/PurchaseOrders/Show', [
            'purchaseOrder' => [
                'id' => $purchaseOrder->id,
                'purchase_order_number' => $purchaseOrder->purchase_order_number,
                'status' => $purchaseOrder->status->value,
                'supplier' => $purchaseOrder->supplier?->only(['id', 'name', 'phone', 'email']),
                'store' => $purchaseOrder->store?->only(['id', 'name']),
                'currency_code' => $purchaseOrder->currency_code,
                'order_date' => $purchaseOrder->order_date?->toDateString(),
                'expected_delivery_date' => $purchaseOrder->expected_delivery_date?->toDateString(),
                'notes' => $purchaseOrder->notes,
                'ordered_at' => $purchaseOrder->ordered_at?->toIso8601String(),
                'created_by' => $purchaseOrder->createdBy?->name,
                'cancelled_at' => $purchaseOrder->cancelled_at?->toIso8601String(),
                'cancelled_by' => $purchaseOrder->cancelledBy?->name,
                'cancellation_reason' => $purchaseOrder->cancellation_reason,
                'lines' => $purchaseOrder->lines->map(function (PurchaseOrderLine $line) use ($evidence) {
                    $source = $evidence->get($line->procurement?->source_supplier_request_id);

                    return [
                        'id' => $line->id,
                        'article' => trim(($line->salesOrderLine?->product_name ?? '').($line->salesOrderLine?->variant_name ? ' — '.$line->salesOrderLine->variant_name : '')),
                        'reference' => $line->productVariant?->reference ?: $line->productVariant?->sku,
                        'quantity' => (string) $line->quantity,
                        'received_quantity' => (string) $line->received_quantity,
                        'remaining_quantity' => Decimal::compare((string) $line->quantity, (string) $line->received_quantity) > 0
                            ? Decimal::subtract((string) $line->quantity, (string) $line->received_quantity) : '0.0000',
                        'unit_price' => $line->unit_price === null ? null : (string) $line->unit_price,
                        'sales_order' => $line->salesOrder?->only(['id', 'order_number']),
                        'sales_order_line' => $line->salesOrderLine ? ['id' => $line->salesOrderLine->id, 'quantity' => (string) $line->salesOrderLine->quantity] : null,
                        'requirement' => $line->procurement ? [
                            'id' => $line->procurement->id, 'number' => $line->procurement->procurement_number, 'status' => $line->procurement->status->value,
                            'received_quantity' => (string) $line->procurement->received_quantity,
                        ] : null,
                        'source_quotation' => $line->procurement?->sourceQuotation?->only(['id', 'quotation_number']),
                        'supplier_evidence' => $source ? [
                            'supplier' => $source->supplier?->name, 'status' => $source->status->value,
                            'confirmed_quantity' => $source->confirmed_quantity === null ? null : (string) $source->confirmed_quantity,
                            'confirmed_at' => $source->resolved_at?->toIso8601String(),
                        ] : null,
                        'receipts' => $line->receipts->map(fn (ProcurementReceipt $receipt) => [
                            'id' => $receipt->id,
                            'quantity' => (string) $receipt->quantity,
                            'received_on' => $receipt->received_on?->toDateString(),
                            'warehouse' => $receipt->warehouse?->only(['id', 'name', 'code']),
                            'supplier_delivery_reference' => $receipt->supplier_delivery_reference,
                            'notes' => $receipt->notes,
                            'received_by' => $receipt->receivedBy?->name,
                        ])->values(),
                    ];
                })->values(),
            ],
            'warehouses' => $canReceive
                ? Warehouse::query()->where('organization_id', $purchaseOrder->organization_id)->where('status', WarehouseStatus::Active->value)
                    ->orderBy('name')->get(['id', 'name', 'code'])
                : [],
            'today' => BusinessTime::today($purchaseOrder->organization),
            'can' => [
                // Nothing received yet: once goods arrived, the purchase order stays as history.
                'cancel' => in_array($purchaseOrder->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Ordered], true)
                    && $purchaseOrder->lines->every(fn (PurchaseOrderLine $line) => Decimal::compare((string) $line->received_quantity, '0') === 0)
                    && $request->user()->can('manage', $purchaseOrder),
                'receive' => $canReceive,
            ],
        ]);
    }

    /** "Créer la commande fournisseur" from a Sales Order: the open requirements of one supplier on that order. */
    public function storeFromSalesOrder(Request $request, SalesOrder $order, CreatePurchaseOrderFromProcurementRequirementsAction $action): RedirectResponse
    {
        $this->authorize('view', $order);
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'expected_delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'client_operation_id' => ['nullable', 'uuid'],
        ]);

        $supplier = Supplier::query()->where('organization_id', $order->organization_id)->whereKey($data['supplier_id'])->firstOrFail();
        $purchaseOrder = $action->forSalesOrder($request->user(), $order, $supplier, [
            'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], $data['client_operation_id'] ?? null);

        return redirect()->route('procurement.purchase-orders.show', $purchaseOrder)
            ->with('success', "Commande fournisseur {$purchaseOrder->purchase_order_number} créée.");
    }

    /** "Réceptionner": a physical receipt against one purchase order line (ReceiveProcurementAction). */
    public function receive(Request $request, PurchaseOrder $purchaseOrder, int $line, ReceiveProcurementAction $action): RedirectResponse
    {
        $this->authorize('receive', $purchaseOrder);
        $poLine = PurchaseOrderLine::query()->where('organization_id', $purchaseOrder->organization_id)
            ->where('purchase_order_id', $purchaseOrder->getKey())->whereKey($line)->with('procurement')->firstOrFail();
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'warehouse_id' => ['required', 'integer'],
            'received_on' => ['nullable', 'date_format:Y-m-d'],
            'supplier_delivery_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'client_operation_id' => ['nullable', 'uuid'],
            'expected_received_quantity' => ['nullable', 'numeric', 'gte:0'],
        ]);
        $warehouse = Warehouse::query()->where('organization_id', $purchaseOrder->organization_id)->whereKey($data['warehouse_id'])->firstOrFail();
        if (! $purchaseOrder->status->isReceivable()) {
            throw ValidationException::withMessages(['purchase_order' => 'Cette commande fournisseur n’est pas ouverte à la réception.']);
        }

        $action->execute($request->user(), $poLine->procurement, $warehouse, [...$data, 'purchase_order_line_id' => $poLine->getKey()]);

        return back()->with('success', 'Réception fournisseur enregistrée.');
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder, CancelPurchaseOrderAction $action): RedirectResponse
    {
        $this->authorize('manage', $purchaseOrder);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $action->execute($request->user(), $purchaseOrder, $data['reason']);

        return back()->with('success', 'Commande fournisseur annulée.');
    }
}
