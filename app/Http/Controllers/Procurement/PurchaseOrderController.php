<?php

namespace App\Http\Controllers\Procurement;

use App\Actions\Procurement\CancelPurchaseOrderAction;
use App\Actions\Procurement\CreatePurchaseOrderFromProcurementRequirementsAction;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Services\ActiveTenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'lines.salesOrder:id,order_number', 'lines.salesOrderLine:id,product_name,variant_name', 'lines.productVariant:id,sku,reference',
            'lines.procurement:id,procurement_number,status',
        ]);

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
                'lines' => $purchaseOrder->lines->map(fn (PurchaseOrderLine $line) => [
                    'id' => $line->id,
                    'article' => trim(($line->salesOrderLine?->product_name ?? '').($line->salesOrderLine?->variant_name ? ' — '.$line->salesOrderLine->variant_name : '')),
                    'reference' => $line->productVariant?->reference ?: $line->productVariant?->sku,
                    'quantity' => (string) $line->quantity,
                    'unit_price' => $line->unit_price === null ? null : (string) $line->unit_price,
                    'sales_order' => $line->salesOrder?->only(['id', 'order_number']),
                    'requirement' => $line->procurement ? ['number' => $line->procurement->procurement_number, 'status' => $line->procurement->status->value] : null,
                ])->values(),
            ],
            'can' => ['cancel' => $purchaseOrder->status !== PurchaseOrderStatus::Cancelled && $request->user()->can('manage', $purchaseOrder)],
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

    public function cancel(Request $request, PurchaseOrder $purchaseOrder, CancelPurchaseOrderAction $action): RedirectResponse
    {
        $this->authorize('manage', $purchaseOrder);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $action->execute($request->user(), $purchaseOrder, $data['reason']);

        return back()->with('success', 'Commande fournisseur annulée.');
    }
}
