<?php

namespace App\Services\Mcp\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Models\Organization;
use App\Models\ProcurementReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\SalesOrder;
use App\Services\Mcp\Sales\McpSalesStoreAccess;
use App\Services\Procurement\SalesOrderProcurementPlanner;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * MCP read model for supplier purchase orders and Sales Order procurement,
 * scoped to the connection organisation and the stores its user belongs to.
 * Procurement figures come from SalesOrderProcurementPlanner (the order page's
 * own calculation), never recomputed here.
 */
final class PurchaseOrderReadService
{
    public function __construct(
        private readonly McpSalesStoreAccess $stores,
        private readonly SalesOrderProcurementPlanner $planner,
    ) {}

    /**
     * @param array{query?: ?string, supplier_id?: ?int, sales_order_id?: ?int, status?: ?string, date_from?: ?string, date_to?: ?string, page?: int, per_page?: int} $filters
     * @return array<string, mixed>
     */
    public function search(Organization $organization, array $filters): array
    {
        $queryText = trim((string) ($filters['query'] ?? ''));
        $status = $filters['status'] ?? null;
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);
        if (mb_strlen($queryText) > 255) throw new InvalidArgumentException('query_too_long');
        if ($status !== null && PurchaseOrderStatus::tryFrom((string) $status) === null) throw new InvalidArgumentException('invalid_status');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) throw new InvalidArgumentException('invalid_pagination');
        foreach (['date_from', 'date_to'] as $date) {
            if (isset($filters[$date]) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters[$date])) throw new InvalidArgumentException('invalid_date');
        }

        $orders = PurchaseOrder::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('store_id', $this->stores->storeIds())
            ->when($queryText !== '', function ($query) use ($queryText) {
                $like = '%'.addcslashes($queryText, '\\%_').'%';
                $query->where(fn ($query) => $query->where('purchase_order_number', 'like', $like)
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', $like)));
            })
            ->when(isset($filters['supplier_id']), fn ($query) => $query->where('supplier_id', (int) $filters['supplier_id']))
            ->when(isset($filters['sales_order_id']), fn ($query) => $query->whereHas('lines', fn ($lines) => $lines->where('sales_order_id', (int) $filters['sales_order_id'])))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when(isset($filters['date_from']), fn ($query) => $query->whereDate('order_date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($query) => $query->whereDate('order_date', '<=', $filters['date_to']))
            ->with(['supplier:id,name', 'store:id,name', 'lines:id,purchase_order_id,sales_order_id,quantity,received_quantity', 'lines.salesOrder:id,order_number'])
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => collect($orders->items())->map(function (PurchaseOrder $po) {
                [$ordered, $received] = $this->totals($po);

                return [
                    'id' => $po->getKey(),
                    'number' => $po->purchase_order_number,
                    'status' => $po->status->value,
                    'supplier' => ['id' => $po->supplier_id, 'name' => $po->supplier?->name],
                    'store' => ['id' => $po->store_id, 'name' => $po->store?->name],
                    'sales_orders' => $po->lines->map(fn (PurchaseOrderLine $line) => ['id' => $line->sales_order_id, 'order_number' => $line->salesOrder?->order_number])->unique('id')->values()->all(),
                    'order_date' => $po->order_date?->toDateString(),
                    'expected_delivery_date' => $po->expected_delivery_date?->toDateString(),
                    'line_count' => $po->lines->count(),
                    'ordered_quantity' => $ordered,
                    'received_quantity' => $received,
                    'remaining_quantity' => $this->atLeastZero(Decimal::subtract($ordered, $received)),
                ];
            })->values()->all(),
            'pagination' => [
                'page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'has_more' => $orders->hasMorePages(),
                'next_page' => $orders->hasMorePages() ? $orders->currentPage() + 1 : null,
            ],
        ];
    }

    /** Organisation + accessible-store scoped lookup; anything else is "not found". */
    public function find(Organization $organization, int $purchaseOrderId, bool $lock = false): PurchaseOrder
    {
        if ($purchaseOrderId < 1) throw new InvalidArgumentException('invalid_id');
        $query = PurchaseOrder::query()->where('organization_id', $organization->getKey())
            ->whereIn('store_id', $this->stores->storeIds())->whereKey($purchaseOrderId);
        if ($lock) $query->lockForUpdate();

        return $query->with(['lines', 'supplier', 'store', 'organization'])->first()
            ?? throw (new ModelNotFoundException)->setModel(PurchaseOrder::class, [$purchaseOrderId]);
    }

    /** Version token over the header and the received quantity of every line. */
    public function version(PurchaseOrder $po): string
    {
        $lines = $po->lines->map(fn (PurchaseOrderLine $line) => $line->getKey().':'.Decimal::normalize((string) $line->received_quantity))->all();

        return hash('sha256', $po->getKey().'|'.$po->status->value.'|'.$po->updated_at?->format('Y-m-d H:i:s.u').'|'.implode(',', $lines));
    }

    /** @return array<string, mixed> */
    public function present(PurchaseOrder $po): array
    {
        $po->load([
            'supplier:id,name,phone,email', 'store:id,name', 'createdBy:id,name',
            'lines.salesOrder:id,order_number,status', 'lines.salesOrderLine:id,product_name,variant_name,quantity', 'lines.productVariant:id,sku,reference',
            'lines.procurement:id,procurement_number,status,quantity,received_quantity,source_quotation_id,source_supplier_request_id',
            'lines.procurement.sourceQuotation:id,quotation_number', 'lines.receipts.warehouse:id,name,code',
        ]);
        $evidence = QuotationSupplierAvailabilityRequest::query()->where('organization_id', $po->organization_id)
            ->whereKey($po->lines->pluck('procurement.source_supplier_request_id')->filter()->unique()->all())
            ->with('supplier:id,name')->get()->keyBy('id');
        [$ordered, $received] = $this->totals($po);

        return [
            'id' => $po->getKey(),
            'number' => $po->purchase_order_number,
            'status' => $po->status->value,
            'receivable' => $po->status->isReceivable(),
            'supplier' => $po->supplier ? ['id' => $po->supplier->id, 'name' => $po->supplier->name, 'phone' => $po->supplier->phone, 'email' => $po->supplier->email] : null,
            'store' => ['id' => $po->store_id, 'name' => $po->store?->name],
            'currency_code' => $po->currency_code,
            'order_date' => $po->order_date?->toDateString(),
            'expected_delivery_date' => $po->expected_delivery_date?->toDateString(),
            'notes' => $po->notes,
            'ordered_at' => $po->ordered_at?->toIso8601String(),
            'created_by' => $po->createdBy?->name,
            'cancelled_at' => $po->cancelled_at?->toIso8601String(),
            'sales_orders' => $po->lines->map(fn (PurchaseOrderLine $line) => ['id' => $line->sales_order_id, 'order_number' => $line->salesOrder?->order_number, 'status' => $line->salesOrder?->status?->value])->unique('id')->values()->all(),
            'totals' => ['ordered_quantity' => $ordered, 'received_quantity' => $received, 'remaining_quantity' => $this->atLeastZero(Decimal::subtract($ordered, $received))],
            'lines' => $po->lines->map(function (PurchaseOrderLine $line) use ($evidence) {
                $source = $evidence->get($line->procurement?->source_supplier_request_id);

                return [
                    'purchase_order_line_id' => $line->getKey(),
                    'article' => [
                        'name' => trim(($line->salesOrderLine?->product_name ?? '').($line->salesOrderLine?->variant_name ? ' — '.$line->salesOrderLine->variant_name : '')),
                        'product_variant_id' => $line->product_variant_id,
                        'sku' => $line->productVariant?->sku,
                        'reference' => $line->productVariant?->reference,
                    ],
                    'ordered_quantity' => Decimal::normalize((string) $line->quantity),
                    'received_quantity' => Decimal::normalize((string) $line->received_quantity),
                    'remaining_quantity' => $this->atLeastZero(Decimal::subtract((string) $line->quantity, (string) $line->received_quantity)),
                    'unit_price' => $line->unit_price === null ? null : (string) $line->unit_price,
                    'sales_order' => ['id' => $line->sales_order_id, 'order_number' => $line->salesOrder?->order_number],
                    'sales_order_line_id' => $line->sales_order_line_id,
                    'requirement' => $line->procurement ? [
                        'id' => $line->procurement->id, 'number' => $line->procurement->procurement_number, 'status' => $line->procurement->status->value,
                        'quantity' => Decimal::normalize((string) $line->procurement->quantity), 'received_quantity' => Decimal::normalize((string) $line->procurement->received_quantity),
                    ] : null,
                    'source_quotation' => $line->procurement?->sourceQuotation ? ['id' => $line->procurement->sourceQuotation->id, 'number' => $line->procurement->sourceQuotation->quotation_number] : null,
                    'supplier_evidence' => $source ? [
                        'supplier' => $source->supplier?->name, 'status' => $source->status->value,
                        'confirmed_quantity' => $source->confirmed_quantity === null ? null : (string) $source->confirmed_quantity,
                    ] : null,
                    'receipts' => $line->receipts->map(fn (ProcurementReceipt $receipt) => [
                        'id' => $receipt->getKey(),
                        'quantity' => Decimal::normalize((string) $receipt->quantity),
                        'received_on' => $receipt->received_on?->toDateString(),
                        'warehouse' => $receipt->warehouse ? ['id' => $receipt->warehouse->id, 'name' => $receipt->warehouse->name, 'code' => $receipt->warehouse->code] : null,
                        'supplier_delivery_reference' => $receipt->supplier_delivery_reference,
                    ])->values()->all(),
                ];
            })->values()->all(),
            'version' => $this->version($po),
            'note' => 'A purchase order is a real supplier commitment; it creates no stock. Goods are added to stock (and reserved for the sales order) only by receive_procurement.',
        ];
    }

    /**
     * Procurement readiness of one Sales Order (SalesOrderProcurementPlanner),
     * with the confirmation blockers and the next step an agent may take.
     *
     * @return array<string, mixed>
     */
    public function salesOrderProcurement(SalesOrder $order): array
    {
        $plan = $this->planner->plan($order);
        $summary = $plan['summary'];
        $lines = collect($plan['lines'])->reject(fn (array $line) => $line['state'] === SalesOrderProcurementPlanner::NOT_APPLICABLE);
        $count = fn (string $state) => $lines->where('state', $state)->count();

        return [
            'sales_order' => ['id' => $order->getKey(), 'order_number' => $order->order_number, 'status' => $order->status->value],
            'state' => $summary['state'],
            'required' => $summary['required'],
            'unresolved_count' => $summary['unresolved_count'],
            'procurement_required_count' => $summary['to_order_line_count'],
            'ordered_count' => $count(SalesOrderProcurementPlanner::ORDERED),
            'partially_received_count' => $count(SalesOrderProcurementPlanner::PARTIALLY_RECEIVED),
            'received_count' => $count(SalesOrderProcurementPlanner::RECEIVED),
            'confirmation_ready' => $summary['confirmation_ready'],
            'confirmation_blockers' => array_map(fn (array $b) => ['code' => $b['code'], 'details' => $b['details']], $this->planner->confirmationBlockers($order)),
            'next_step' => self::nextStep($order, $summary),
            'suppliers_to_order' => array_map(fn (array $group) => ['supplier' => $group['supplier'], 'line_count' => $group['line_count']], $summary['suppliers']),
            'lines' => $lines->map(fn (array $line) => self::line($line))->values()->all(),
        ];
    }

    /** @param array<string, mixed> $line @return array<string, mixed> */
    public static function line(array $line): array
    {
        return [
            'sales_order_line_id' => $line['line_id'],
            'article' => ['name' => $line['article'], 'product_variant_id' => $line['product_variant_id']],
            'state' => $line['state'],
            'requested_quantity' => $line['requested_quantity'],
            'stock_covered_quantity' => $line['internal_stock_covered_quantity'],
            'reserved_for_this_order' => $line['already_reserved_for_this_order'],
            'procurement_required_quantity' => $line['procurement_quantity'],
            'remaining_to_order' => $line['remaining_to_order'],
            'ordered_quantity' => $line['procurement_ordered'],
            'received_quantity' => $line['procurement_received'],
            'remaining_to_receive' => $line['procurement_remaining'],
            'unresolved_reason' => $line['unresolved_reason'],
            'supplier' => $line['supplier'],
            'availability_evidence' => $line['availability'],
            'source_quotation' => $line['source_quotation'],
            'purchase_orders' => array_map(fn (array $po) => array_intersect_key($po, array_flip(['id', 'line_id', 'number', 'status', 'quantity', 'received_quantity', 'remaining_to_receive', 'expected_delivery_date'])), $line['purchase_orders']),
            'confirmation_ready' => $line['confirmation_ready'],
        ];
    }

    /**
     * What an agent can do next on this order's procurement (never done automatically).
     *
     * @param array<string, mixed> $summary
     */
    public static function nextStep(SalesOrder $order, array $summary): ?string
    {
        return match (true) {
            ! in_array($order->status->value, ['draft', 'confirmed'], true) => null,
            $summary['unresolved_count'] > 0 => 'resolve_supplier',
            $summary['to_order_line_count'] > 0 => 'create_purchase_order',
            ($summary['receipt_pending_line_count'] ?? 0) > 0 => 'receive_procurement',
            $order->status->value === 'draft' && $summary['confirmation_ready'] => 'confirm_sales_order',
            default => null,
        };
    }

    /** @return array{0: string, 1: string} ordered, received */
    private function totals(PurchaseOrder $po): array
    {
        return [
            $po->lines->reduce(fn (string $sum, PurchaseOrderLine $line) => Decimal::add($sum, (string) $line->quantity), '0.0000'),
            $po->lines->reduce(fn (string $sum, PurchaseOrderLine $line) => Decimal::add($sum, (string) $line->received_quantity), '0.0000'),
        ];
    }

    private function atLeastZero(string $value): string
    {
        return Decimal::compare($value, '0') < 0 ? '0.0000' : Decimal::normalize($value);
    }
}
