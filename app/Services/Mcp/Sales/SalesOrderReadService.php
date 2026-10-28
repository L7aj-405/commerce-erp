<?php

namespace App\Services\Mcp\Sales;

use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderPaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderLine;
use App\Services\Procurement\SalesOrderProcurementPlanner;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * MCP read model for Sales Orders, scoped to the connection organisation and
 * the stores its user belongs to (the same store rule the Sales actions use).
 * Payment figures come from SalesOrderPaymentCalculator, never recomputed here.
 */
final class SalesOrderReadService
{
    public function __construct(
        private readonly McpSalesStoreAccess $stores,
        private readonly SalesOrderPaymentCalculator $payments,
        private readonly SalesOrderProcurementPlanner $procurement,
    ) {}

    /**
     * @param array{query?: ?string, status?: ?string, payment_status?: ?string, customer_id?: ?int, store_id?: ?int, date_from?: ?string, date_to?: ?string, page?: int, per_page?: int} $filters
     * @return array<string, mixed>
     */
    public function search(Organization $organization, array $filters): array
    {
        $queryText = trim((string) ($filters['query'] ?? ''));
        $status = $filters['status'] ?? null;
        $paymentStatus = $filters['payment_status'] ?? null;
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        if (mb_strlen($queryText) > 255) throw new InvalidArgumentException('query_too_long');
        if ($status !== null && SalesOrderStatus::tryFrom((string) $status) === null) throw new InvalidArgumentException('invalid_status');
        if ($paymentStatus !== null && SalesOrderPaymentStatus::tryFrom((string) $paymentStatus) === null) throw new InvalidArgumentException('invalid_status');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) throw new InvalidArgumentException('invalid_pagination');
        foreach ([$dateFrom, $dateTo] as $date) {
            if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) throw new InvalidArgumentException('invalid_date');
        }

        $storeIds = $this->stores->storeIds();
        if (isset($filters['store_id'])) {
            $storeIds = in_array((int) $filters['store_id'], $storeIds, true) ? [(int) $filters['store_id']] : [];
        }

        $orders = SalesOrder::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('store_id', $storeIds)
            ->when($queryText !== '', function ($query) use ($queryText) {
                $like = '%'.addcslashes($queryText, '\\%_').'%';
                $query->where(fn ($query) => $query
                    ->where('order_number', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)->orWhere('customer_company', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like)->orWhere('customer_email', 'like', $like));
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($paymentStatus !== null, fn ($query) => $query->where('payment_status', $paymentStatus))
            ->when(isset($filters['customer_id']), fn ($query) => $query->where('customer_id', (int) $filters['customer_id']))
            ->when($dateFrom !== null, fn ($query) => $query->whereDate('sale_date', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->whereDate('sale_date', '<=', $dateTo))
            ->with(['store:id,name'])
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        // Batch form of the authoritative payment summary: no per-row queries.
        $summaries = $this->payments->summaries($orders->items());

        return [
            'items' => collect($orders->items())->map(fn (SalesOrder $order) => [
                'id' => $order->getKey(),
                'order_number' => $order->order_number,
                'status' => $order->status->value,
                'payment_status' => $order->payment_status->value,
                'fulfillment_status' => $order->fulfillment_status->value,
                'source' => $order->source->value,
                'store' => ['id' => $order->store_id, 'name' => $order->store?->name],
                'customer' => $this->customer($order, false),
                'sale_date' => $order->sale_date?->toDateString(),
                'currency_code' => $order->currency_code,
                'total_incl_tax' => (string) $order->total_incl_tax,
                'paid' => $summaries[$order->getKey()]['paid'] ?? null,
                'remaining' => $summaries[$order->getKey()]['remaining'] ?? null,
                'confirmed_at' => $order->confirmed_at?->toIso8601String(),
                'created_at' => $order->created_at?->toIso8601String(),
                'updated_at' => $order->updated_at?->toIso8601String(),
            ])->values()->all(),
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
    public function find(Organization $organization, int $orderId, bool $lock = false): SalesOrder
    {
        if ($orderId < 1) throw new InvalidArgumentException('invalid_id');

        $query = SalesOrder::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('store_id', $this->stores->storeIds())
            ->whereKey($orderId);
        if ($lock) $query->lockForUpdate();

        return $query->with(['lines', 'store', 'organization', 'currentRevision'])->first()
            ?? throw (new ModelNotFoundException)->setModel(SalesOrder::class, [$orderId]);
    }

    /** Version token over the header row and every line row. */
    public function version(SalesOrder $order): string
    {
        $lines = $order->lines->map(fn (SalesOrderLine $line) => $line->getKey().'@'.$line->updated_at?->format('Y-m-d H:i:s.u'))->all();

        return hash('sha256', $order->getKey().'|'.$order->status->value.'|'.$order->updated_at?->format('Y-m-d H:i:s.u').'|'.implode(',', $lines));
    }

    /** @return array<string, mixed> */
    public function present(SalesOrder $order, bool $withAllocations): array
    {
        $order->loadMissing(['store', 'confirmedBy:id,name']);
        if ($withAllocations) $order->loadMissing(['lines.allocations.warehouse:id,name,code', 'lines.allocations.inventoryReservation:id,status']);
        $payment = $this->payments->summary($order);

        return [
            'id' => $order->getKey(),
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'editable' => $order->isCommerciallyEditable(),
            'source' => $order->source->value,
            'store' => ['id' => $order->store_id, 'name' => $order->store?->name],
            'customer' => $this->customer($order, true),
            'salesperson_name' => $order->salesperson_name_snapshot,
            'sale_date' => $order->sale_date?->toDateString(),
            'currency_code' => $order->currency_code,
            'notes' => $order->notes,
            'lines' => $order->lines->map(fn (SalesOrderLine $line) => $this->line($line, $withAllocations))->values()->all(),
            'totals' => $this->totals($order),
            'payment' => [
                'status' => $order->payment_status->value,
                'total' => $payment['commercial_total'],
                'paid' => $payment['paid'],
                'remaining' => $payment['remaining'],
                'fully_paid' => $order->status === SalesOrderStatus::Confirmed && Decimal::compare($payment['remaining'], '0') === 0,
                'last_payment_date' => $payment['last_payment_date'],
                // Latest non-cancelled invoice of the order; detailed reads: get_invoice / search_payments.
                'invoice' => $this->invoiceSummary($order),
            ],
            'fulfillment_status' => $order->fulfillment_status->value,
            // Supplier procurement readiness, independent of the commercial status. Compact:
            // detailed supplier request histories stay on the procurement pages.
            'procurement' => $this->procurementSummary($order),
            'confirmation' => $order->confirmed_at ? [
                'confirmed_at' => $order->confirmed_at->toIso8601String(),
                'confirmed_by' => $order->confirmedBy?->name,
            ] : null,
            'version' => $this->version($order),
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function procurementSummary(SalesOrder $order): array
    {
        $plan = $this->procurement->plan($order);
        $summary = $plan['summary'];

        return [
            'state' => $summary['state'],
            'required' => $summary['required'],
            'ready_for_ordering' => $summary['ready_for_ordering'],
            'ordered' => $summary['ordered'],
            'unresolved_count' => $summary['unresolved_count'],
            'required_line_count' => $summary['required_line_count'],
            'ordered_line_count' => $summary['ordered_line_count'],
            'confirmation_waits_for_receipt' => $summary['confirmation_waits_for_receipt'],
            'confirmation_ready' => $summary['confirmation_ready'],
            'source_quotation' => $summary['source_quotation'],
            'lines' => collect($plan['lines'])->reject(fn (array $line) => $line['state'] === SalesOrderProcurementPlanner::NOT_APPLICABLE)
                ->map(fn (array $line) => [
                    'line_id' => $line['line_id'],
                    'article' => $line['article'],
                    'state' => $line['state'],
                    'required_quantity' => $line['procurement_quantity'],
                    'ordered_quantity' => $line['ordered_quantity'],
                    'remaining_to_order' => $line['remaining_to_order'],
                    'internal_stock_covered_quantity' => $line['internal_stock_covered_quantity'],
                    'internal_available_unreserved' => $line['internal_available_unreserved'],
                    'already_reserved_for_this_order' => $line['already_reserved_for_this_order'],
                    'procurement_ordered' => $line['procurement_ordered'],
                    'procurement_received' => $line['procurement_received'],
                    'procurement_remaining' => $line['procurement_remaining'],
                    'total_covered_quantity' => $line['total_covered_quantity'],
                    'confirmation_ready' => $line['confirmation_ready'],
                    'unresolved_reason' => $line['unresolved_reason'],
                    'supplier' => $line['supplier'],
                    'availability' => $line['availability'],
                    'purchase_orders' => array_map(fn (array $po) => array_intersect_key($po, array_flip(['id', 'number', 'status', 'quantity', 'received_quantity', 'remaining_to_receive', 'expected_delivery_date'])), $line['purchase_orders']),
                    'source_quotation' => $line['source_quotation'],
                ])->values()->all(),
        ];
    }

    /** @return array{id: int, invoice_number: ?string, status: string}|null */
    private function invoiceSummary(SalesOrder $order): ?array
    {
        $invoice = Invoice::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())
            ->where('status', '!=', InvoiceStatus::Cancelled->value)->orderByDesc('version')->orderByDesc('id')
            ->first(['id', 'invoice_number', 'status']);

        return $invoice ? ['id' => (int) $invoice->getKey(), 'invoice_number' => $invoice->invoice_number, 'status' => $invoice->status->value] : null;
    }

    /** @return array<string, mixed> */
    public function line(SalesOrderLine $line, bool $withAllocations = false): array
    {
        $decimal = fn ($value) => $value === null ? null : (string) $value;
        $data = [
            'line_id' => $line->getKey(),
            'position' => $line->position,
            'line_type' => $line->line_type->value,
            'product_variant_id' => $line->product_variant_id,
            'product_name' => $line->product_name,
            'variant_name' => $line->variant_name,
            'sku' => $line->sku,
            'reference' => $line->reference,
            'unit_label' => $line->unit_label,
            'quantity' => $decimal($line->quantity),
            'unit_price_excl_tax' => $decimal($line->unit_price_excl_tax),
            'unit_price_incl_tax' => $decimal($line->unit_price_incl_tax),
            'discount_type' => $line->discount_type?->value,
            'discount_value' => $decimal($line->discount_value),
            'subtotal_excl_tax' => $decimal($line->subtotal_excl_tax),
            'discount_amount' => $decimal($line->discount_amount),
            'taxable_amount' => $decimal($line->taxable_amount),
            'tax_name' => $line->tax_name,
            'tax_rate' => $decimal($line->tax_rate),
            'tax_unresolved' => (bool) $line->tax_unresolved,
            'tax_amount' => $decimal($line->tax_amount),
            'total_incl_tax' => $decimal($line->total_incl_tax),
        ];
        if ($withAllocations) {
            $data['allocations'] = $line->allocations->map(fn (SalesOrderInventoryAllocation $allocation) => [
                'warehouse' => ['id' => $allocation->warehouse_id, 'name' => $allocation->warehouse?->name, 'code' => $allocation->warehouse?->code],
                'quantity' => (string) $allocation->quantity,
                'reserved' => $allocation->inventory_reservation_id !== null,
                'reservation_status' => $allocation->inventoryReservation?->status?->value,
            ])->values()->all();
        }

        return $data;
    }

    /** @return array{subtotal_excl_tax: string, discount_total: string, tax_total: string, total_incl_tax: string} */
    public function totals(SalesOrder $order): array
    {
        return [
            'subtotal_excl_tax' => (string) $order->subtotal_excl_tax,
            'discount_total' => (string) $order->discount_total,
            'tax_total' => (string) $order->tax_total,
            'total_incl_tax' => (string) $order->total_incl_tax,
        ];
    }

    /** @return array<string, mixed> */
    private function customer(SalesOrder $order, bool $full): array
    {
        $customer = ['id' => $order->customer_id, 'name' => $order->customer_name, 'company' => $order->customer_company];

        return $full ? [...$customer, 'email' => $order->customer_email, 'phone' => $order->customer_phone] : $customer;
    }
}
