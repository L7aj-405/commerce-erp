<?php

namespace App\Http\Controllers\Procurement;

use App\Actions\Procurement\CancelProcurementAction;
use App\Actions\Procurement\ChangeProcurementSupplierAction;
use App\Actions\Procurement\CreateProcurementAction;
use App\Actions\Procurement\OrderProcurementAction;
use App\Actions\Procurement\ReceiveProcurementAction;
use App\Actions\Procurement\RecordSupplierAvailabilityAction;
use App\Enums\SupplierAvailabilityStatus;
use App\Enums\SupplierProcurementStatus;
use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesOrderProcurement;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ProcurementController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): InertiaResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [SalesOrderProcurement::class, $organization]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(SupplierProcurementStatus::class)],
        ]);

        $procurements = SalesOrderProcurement::query()
            ->where('organization_id', $organization->getKey())
            ->with(['supplier:id,name', 'salesOrder:id,order_number', 'productVariant:id,label,sku,reference'])
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('procurement_number', 'like', "%{$search}%")
                ->orWhereHas('salesOrder', fn ($order) => $order->where('order_number', 'like', "%{$search}%"))
                ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', "%{$search}%"))))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SalesOrderProcurement $p) => [
                'id' => $p->id,
                'procurement_number' => $p->procurement_number,
                'sales_order' => $p->salesOrder?->only(['id', 'order_number']),
                'supplier' => $p->supplier?->only(['id', 'name']),
                'product' => trim(($p->productVariant?->label ?? '').' '.($p->productVariant?->reference ?? $p->productVariant?->sku ?? '')),
                'quantity' => $p->quantity,
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'supplier_availability_status' => $p->supplier_availability_status->value,
                'ordered_at' => $p->ordered_at?->toIso8601String(),
                'expected_at' => $p->expected_at?->toDateString(),
                'received_at' => $p->received_at?->toIso8601String(),
            ]);

        return Inertia::render('Procurement/Index', [
            'procurements' => $procurements,
            'filters' => $filters,
            'can' => [
                'manage' => $request->user()->hasPermission($organization, 'procurement.manage'),
                'receive' => $request->user()->hasPermission($organization, 'procurement.receive'),
                'suppliers' => $request->user()->hasPermission($organization, 'suppliers.view'),
            ],
        ]);
    }

    public function store(Request $request, SalesOrder $order, CreateProcurementAction $action): RedirectResponse|JsonResponse
    {
        $this->authorize('view', $order);

        $data = $request->validate([
            'sales_order_line_id' => ['required', 'integer'],
            'supplier_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'supplier_reference' => ['nullable', 'string', 'max:255'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $line = SalesOrderLine::query()->where('organization_id', $order->organization_id)
            ->where('sales_order_id', $order->getKey())->whereKey($data['sales_order_line_id'])->firstOrFail();
        $supplier = Supplier::query()->where('organization_id', $order->organization_id)
            ->whereKey($data['supplier_id'])->firstOrFail();

        $procurement = $action->execute($request->user(), $order, $line, $supplier, $data);

        return $request->expectsJson()
            ? response()->json(['data' => $this->summary($procurement)], 201)
            : back()->with('success', 'Approvisionnement fournisseur créé.');
    }

    public function availability(Request $request, SalesOrderProcurement $procurement, RecordSupplierAvailabilityAction $action): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $procurement);

        $data = $request->validate([
            'supplier_availability_status' => ['required', Rule::enum(SupplierAvailabilityStatus::class)],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'supplier_reference' => ['nullable', 'string', 'max:255'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $procurement = $action->execute($request->user(), $procurement, $data);

        return $request->expectsJson()
            ? response()->json(['data' => $this->summary($procurement)])
            : back()->with('success', 'Disponibilité fournisseur enregistrée.');
    }

    /** @return array<string, mixed> */
    private function summary(SalesOrderProcurement $procurement): array
    {
        return [
            'id' => $procurement->id,
            'procurement_number' => $procurement->procurement_number,
            'status' => $procurement->status->value,
            'status_label' => $procurement->status->label(),
            'supplier_availability_status' => $procurement->supplier_availability_status->value,
            'quantity' => $procurement->quantity,
            'sales_order_line_id' => $procurement->sales_order_line_id,
        ];
    }

    public function changeSupplier(Request $request, SalesOrderProcurement $procurement, ChangeProcurementSupplierAction $action): RedirectResponse
    {
        $this->authorize('manage', $procurement);

        $data = $request->validate(['supplier_id' => ['required', 'integer']]);
        $supplier = Supplier::query()->where('organization_id', $procurement->organization_id)
            ->whereKey($data['supplier_id'])->firstOrFail();
        $action->execute($request->user(), $procurement, $supplier);

        return back()->with('success', 'Fournisseur remplacé.');
    }

    public function order(Request $request, SalesOrderProcurement $procurement, OrderProcurementAction $action): RedirectResponse
    {
        $this->authorize('manage', $procurement);

        $data = $request->validate([
            'supplier_reference' => ['nullable', 'string', 'max:255'],
            'expected_at' => ['nullable', 'date'],
        ]);
        $action->execute($request->user(), $procurement, $data);

        return back()->with('success', 'Commande fournisseur enregistrée.');
    }

    public function receive(Request $request, SalesOrderProcurement $procurement, ReceiveProcurementAction $action): RedirectResponse
    {
        $this->authorize('receive', $procurement);

        $data = $request->validate([
            'receiving_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query
                ->where('organization_id', $procurement->organization_id)->where('status', 'active'))],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);
        $warehouse = Warehouse::query()->where('organization_id', $procurement->organization_id)
            ->whereKey($data['receiving_warehouse_id'])->firstOrFail();

        $action->execute($request->user(), $procurement, $warehouse, $data);

        return back()->with('success', 'Réception fournisseur confirmée.');
    }

    public function cancel(Request $request, SalesOrderProcurement $procurement, CancelProcurementAction $action): RedirectResponse
    {
        $this->authorize('manage', $procurement);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
            'acknowledge_ordered' => ['sometimes', 'boolean'],
        ]);
        $action->execute($request->user(), $procurement, $data['reason'] ?? null, (bool) ($data['acknowledge_ordered'] ?? false));

        return back()->with('success', 'Approvisionnement annulé.');
    }
}
