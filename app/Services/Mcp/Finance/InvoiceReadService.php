<?php

namespace App\Services\Mcp\Finance;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Services\Mcp\Sales\McpSalesStoreAccess;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * Invoice reads for MCP: organization + the connection user's member stores,
 * exactly like quotations and sales orders. Issued snapshots are returned as
 * frozen; the receivable side (paid / outstanding) comes from the source
 * Sales Order through SalesOrderPaymentCalculator — never recomputed here.
 */
final class InvoiceReadService
{
    public function __construct(
        private readonly McpSalesStoreAccess $stores,
        private readonly SalesOrderPaymentCalculator $payments,
    ) {}

    /**
     * @param array{query?: ?string, status?: ?string, customer_id?: ?int, sales_order_id?: ?int, store_id?: ?int, date_from?: ?string, date_to?: ?string, page?: int, per_page?: int} $filters
     * @return array<string, mixed>
     */
    public function search(Organization $organization, array $filters, bool $withPayment): array
    {
        $queryText = trim((string) ($filters['query'] ?? ''));
        $status = $filters['status'] ?? null;
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        if (mb_strlen($queryText) > 255) throw new InvalidArgumentException('query_too_long');
        if ($status !== null && InvoiceStatus::tryFrom((string) $status) === null) throw new InvalidArgumentException('invalid_status');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) throw new InvalidArgumentException('invalid_pagination');
        foreach ([$dateFrom, $dateTo] as $date) {
            if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) throw new InvalidArgumentException('invalid_date');
        }

        $storeIds = $this->stores->storeIds();
        if (isset($filters['store_id'])) {
            $storeIds = in_array((int) $filters['store_id'], $storeIds, true) ? [(int) $filters['store_id']] : [];
        }

        $invoices = Invoice::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('store_id', $storeIds)
            ->when($queryText !== '', function ($query) use ($queryText) {
                $like = '%'.addcslashes($queryText, '\\%_').'%';
                $query->where(fn ($query) => $query
                    ->where('invoice_number', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('customer_company', 'like', $like)
                    ->orWhereHas('salesOrder', fn ($order) => $order->where('order_number', 'like', $like)));
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when(isset($filters['customer_id']), fn ($query) => $query->where('customer_id', (int) $filters['customer_id']))
            ->when(isset($filters['sales_order_id']), fn ($query) => $query->where('sales_order_id', (int) $filters['sales_order_id']))
            ->when($dateFrom !== null, fn ($query) => $query->whereDate('invoice_date', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->whereDate('invoice_date', '<=', $dateTo))
            ->with(['store:id,name', 'salesOrder'])
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        // Batch form of the authoritative payment summary: no per-row queries.
        $summaries = $withPayment ? $this->payments->summaries(collect($invoices->items())->pluck('salesOrder')->filter()->unique('id')) : [];

        return [
            'items' => collect($invoices->items())->map(fn (Invoice $invoice) => [
                ...$this->summary($invoice),
                ...($withPayment ? ['payment' => $this->paymentBlock($summaries[$invoice->sales_order_id] ?? null)] : []),
            ])->values()->all(),
            'pagination' => [
                'page' => $invoices->currentPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
                'has_more' => $invoices->hasMorePages(),
                'next_page' => $invoices->hasMorePages() ? $invoices->currentPage() + 1 : null,
            ],
        ];
    }

    /** Organisation + accessible-store scoped lookup; anything else is "not found". */
    public function find(Organization $organization, int $invoiceId): Invoice
    {
        if ($invoiceId < 1) throw new InvalidArgumentException('invalid_id');

        return Invoice::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('store_id', $this->stores->storeIds())
            ->whereKey($invoiceId)
            ->with(['lines', 'store', 'salesOrder', 'issuedBy:id,name'])
            ->first() ?? throw (new ModelNotFoundException)->setModel(Invoice::class, [$invoiceId]);
    }

    /** @return array<string, mixed> */
    public function invoice(Organization $organization, int $invoiceId, bool $withPayment): array
    {
        return $this->present($this->find($organization, $invoiceId), $withPayment);
    }

    /** @return array<string, mixed> */
    public function present(Invoice $invoice, bool $withPayment): array
    {
        $invoice->loadMissing(['lines', 'store', 'salesOrder', 'issuedBy:id,name']);

        return [
            ...$this->summary($invoice),
            'version_number' => (int) $invoice->version,
            'immutable' => $invoice->status !== InvoiceStatus::Draft,
            'customer' => [
                'id' => $invoice->customer_id,
                'name' => $invoice->customer_name,
                'company' => $invoice->customer_company,
                'email' => $invoice->customer_email,
                'phone' => $invoice->customer_phone,
                'tax_identifier' => $invoice->customer_tax_identifier,
                'billing_address' => $invoice->billing_address,
            ],
            'due_date' => $invoice->due_date?->toDateString(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'issued_by' => $invoice->issuedBy?->name,
            'payment_method_summary' => $invoice->payment_method_summary,
            'corrected_invoice_id' => $invoice->corrected_invoice_id,
            'lines' => $invoice->lines->sortBy('position')->map(fn (InvoiceLine $line) => [
                'line_id' => $line->getKey(),
                'position' => $line->position,
                'line_type' => $line->line_type instanceof \BackedEnum ? $line->line_type->value : $line->line_type,
                'product_variant_id' => $line->product_variant_id,
                'product_name' => $line->product_name,
                'variant_name' => $line->variant_name,
                'sku' => $line->sku,
                'reference' => $line->reference,
                'unit_label' => $line->unit_label,
                'quantity' => (string) $line->quantity,
                'unit_price_excl_tax' => (string) $line->unit_price_excl_tax,
                'unit_price_incl_tax' => (string) $line->unit_price_incl_tax,
                'discount_amount' => (string) $line->discount_amount,
                'taxable_amount' => (string) $line->taxable_amount,
                'tax_name' => $line->tax_name,
                'tax_rate' => (string) $line->tax_rate,
                'tax_amount' => (string) $line->tax_amount,
                'total_incl_tax' => (string) $line->total_incl_tax,
            ])->values()->all(),
            'taxes' => $this->taxes($invoice),
            'totals' => [
                'subtotal_excl_tax' => (string) $invoice->subtotal_excl_tax,
                'discount_total' => (string) $invoice->discount_total,
                'tax_total' => (string) $invoice->tax_total,
                'total_incl_tax' => (string) $invoice->total_incl_tax,
            ],
            ...($withPayment ? ['payment' => $this->paymentBlock($invoice->salesOrder ? $this->payments->summary($invoice->salesOrder) : null)] : []),
            'version' => $this->version($invoice),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(Invoice $invoice): array
    {
        return [
            'id' => $invoice->getKey(),
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'store' => ['id' => $invoice->store_id, 'name' => $invoice->store?->name],
            'customer_id' => $invoice->customer_id,
            'customer_name' => $invoice->customer_name,
            'customer_company' => $invoice->customer_company,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'currency_code' => $invoice->currency_code,
            'total_incl_tax' => (string) $invoice->total_incl_tax,
            'sales_order' => $invoice->salesOrder ? ['id' => $invoice->sales_order_id, 'order_number' => $invoice->salesOrder->order_number] : null,
        ];
    }

    public function version(Invoice $invoice): string
    {
        return hash('sha256', $invoice->getKey().'|'.$invoice->status->value.'|'.$invoice->updated_at?->format('Y-m-d H:i:s.u'));
    }

    /**
     * Receivable state of the source Sales Order (what has been paid on it).
     *
     * @param array<string, string|null>|null $summary SalesOrderPaymentCalculator summary
     * @return array<string, mixed>|null
     */
    private function paymentBlock(?array $summary): ?array
    {
        if ($summary === null) return null;

        return [
            'paid_amount' => $summary['paid'],
            'outstanding_amount' => $summary['remaining'],
            'payment_status' => $summary['status'],
        ];
    }

    /** Tax breakdown of the frozen lines, grouped by rate (display only). @return list<array<string, string|null>> */
    private function taxes(Invoice $invoice): array
    {
        $groups = [];
        foreach ($invoice->lines as $line) {
            $key = (string) $line->tax_rate.'|'.($line->tax_name ?? '');
            $groups[$key] ??= ['tax_name' => $line->tax_name, 'tax_rate' => (string) $line->tax_rate, 'taxable_amount' => '0.0000', 'tax_amount' => '0.0000'];
            $groups[$key]['taxable_amount'] = Decimal::add($groups[$key]['taxable_amount'], (string) $line->taxable_amount);
            $groups[$key]['tax_amount'] = Decimal::add($groups[$key]['tax_amount'], (string) $line->tax_amount);
        }

        return array_values($groups);
    }
}
