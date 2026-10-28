<?php

namespace App\Services\Mcp\Finance;

use App\Enums\FinancialAccountStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Services\Mcp\Sales\McpSalesStoreAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * Payment reads for MCP (organization + the user's member stores) and the
 * canonical payment means: a Payment is a PaymentMethod (ERP enum: cash,
 * card, bank_transfer, cheque) received into an active FinancialAccount that
 * accepts that method (FinancialAccount::acceptsMethod) — the same pair the
 * web payment form requires.
 */
final class PaymentReadService
{
    public function __construct(private readonly McpSalesStoreAccess $stores) {}

    /**
     * Methods with the active financial accounts that accept them.
     *
     * @return array<string, mixed>
     */
    public function methods(Organization $organization, ?string $currencyCode = null): array
    {
        $accounts = FinancialAccount::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', FinancialAccountStatus::Active->value)
            ->when($currencyCode !== null, fn ($query) => $query->where('currency_code', $currencyCode))
            ->orderBy('name')->orderBy('id')->limit(200)->get();

        return [
            'methods' => collect(PaymentMethod::cases())->map(fn (PaymentMethod $method) => [
                'method' => $method->value,
                'label' => $method->operationalLabel(),
                'financial_accounts' => $accounts->filter(fn (FinancialAccount $account) => $account->acceptsMethod($method))
                    ->map(fn (FinancialAccount $account) => [
                        'id' => $account->getKey(),
                        'name' => $account->name,
                        'code' => $account->code,
                        'type' => $account->type instanceof \BackedEnum ? $account->type->value : $account->type,
                        'currency_code' => $account->currency_code,
                    ])->values()->all(),
            ])->values()->all(),
            'note' => 'record_payment needs a method AND a financial_account_id listed under that method, in the Sales Order currency. Never guess an id.',
        ];
    }

    /**
     * @param array{query?: ?string, status?: ?string, method?: ?string, financial_account_id?: ?int, customer_id?: ?int, sales_order_id?: ?int, invoice_id?: ?int, store_id?: ?int, date_from?: ?string, date_to?: ?string, page?: int, per_page?: int} $filters
     * @return array<string, mixed>
     */
    public function search(Organization $organization, array $filters): array
    {
        $queryText = trim((string) ($filters['query'] ?? ''));
        $status = $filters['status'] ?? null;
        $method = $filters['method'] ?? null;
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        if (mb_strlen($queryText) > 255) throw new InvalidArgumentException('query_too_long');
        if ($status !== null && PaymentStatus::tryFrom((string) $status) === null) throw new InvalidArgumentException('invalid_status');
        if ($method !== null && PaymentMethod::tryFrom((string) $method) === null) throw new InvalidArgumentException('invalid_method');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) throw new InvalidArgumentException('invalid_pagination');
        foreach ([$dateFrom, $dateTo] as $date) {
            if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) throw new InvalidArgumentException('invalid_date');
        }

        $storeIds = $this->stores->storeIds();
        if (isset($filters['store_id'])) {
            $storeIds = in_array((int) $filters['store_id'], $storeIds, true) ? [(int) $filters['store_id']] : [];
        }

        // An invoice filter resolves to its Sales Order (payments are allocated to orders).
        $orderId = $filters['sales_order_id'] ?? null;
        if (isset($filters['invoice_id'])) {
            $invoiceOrder = Invoice::query()->where('organization_id', $organization->getKey())->whereIn('store_id', $storeIds)
                ->whereKey((int) $filters['invoice_id'])->value('sales_order_id');
            $orderId = $invoiceOrder ?? -1;
        }

        $payments = Payment::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('store_id', $storeIds)
            ->when($queryText !== '', function ($query) use ($queryText) {
                $like = '%'.addcslashes($queryText, '\\%_').'%';
                $query->where(fn ($query) => $query
                    ->where('payment_number', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhere('external_reference', 'like', $like)
                    ->orWhereHas('allocations.salesOrder', fn ($order) => $order
                        ->where('order_number', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)
                        ->orWhere('customer_company', 'like', $like)));
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($method !== null, fn ($query) => $query->where('method', $method))
            ->when(isset($filters['financial_account_id']), fn ($query) => $query->where('financial_account_id', (int) $filters['financial_account_id']))
            ->when($orderId !== null, fn ($query) => $query->whereHas('allocations', fn ($allocation) => $allocation->where('sales_order_id', (int) $orderId)))
            ->when(isset($filters['customer_id']), fn ($query) => $query->whereHas('allocations.salesOrder', fn ($order) => $order->where('customer_id', (int) $filters['customer_id'])))
            ->when($dateFrom !== null, fn ($query) => $query->whereDate('payment_date', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->whereDate('payment_date', '<=', $dateTo))
            ->with(['financialAccount:id,name,code', 'allocations.salesOrder:id,order_number,customer_id,customer_name,customer_company'])
            ->orderByDesc('payment_date')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => collect($payments->items())->map(fn (Payment $payment) => $this->summary($payment))->values()->all(),
            'pagination' => [
                'page' => $payments->currentPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'has_more' => $payments->hasMorePages(),
                'next_page' => $payments->hasMorePages() ? $payments->currentPage() + 1 : null,
            ],
        ];
    }

    public function find(Organization $organization, int $paymentId): Payment
    {
        if ($paymentId < 1) throw new InvalidArgumentException('invalid_id');

        return Payment::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('store_id', $this->stores->storeIds())
            ->whereKey($paymentId)
            ->first() ?? throw (new ModelNotFoundException)->setModel(Payment::class, [$paymentId]);
    }

    /** @return array<string, mixed> */
    public function payment(Organization $organization, int $paymentId): array
    {
        return $this->present($this->find($organization, $paymentId));
    }

    /** @return array<string, mixed> */
    public function present(Payment $payment): array
    {
        $payment->loadMissing(['store:id,name', 'financialAccount:id,name,code', 'allocations.salesOrder:id,order_number,customer_id,customer_name,customer_company', 'receivedBy:id,name', 'reversedBy:id,name']);
        $orderIds = $payment->allocations->pluck('sales_order_id')->filter()->unique()->values();
        $invoices = Invoice::query()->where('organization_id', $payment->organization_id)->whereIn('sales_order_id', $orderIds)
            ->whereIn('status', ['issued', 'superseded'])->orderBy('id')->get(['id', 'sales_order_id', 'invoice_number', 'status', 'version']);

        return [
            ...$this->summary($payment),
            'store' => ['id' => $payment->store_id, 'name' => $payment->store?->name],
            'external_reference' => $payment->external_reference,
            'received_by' => $payment->receivedBy?->name,
            'reversed_at' => $payment->reversed_at?->toIso8601String(),
            'reversed_by' => $payment->reversedBy?->name,
            'allocations' => $payment->allocations->map(fn ($allocation) => [
                'sales_order_id' => $allocation->sales_order_id,
                'order_number' => $allocation->salesOrder?->order_number,
                'amount' => (string) $allocation->amount,
                'invoices' => $invoices->where('sales_order_id', $allocation->sales_order_id)->map(fn (Invoice $invoice) => [
                    'id' => $invoice->getKey(), 'invoice_number' => $invoice->invoice_number, 'status' => $invoice->status->value, 'version' => (int) $invoice->version,
                ])->values()->all(),
            ])->values()->all(),
            'immutable' => true,
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(Payment $payment): array
    {
        $order = $payment->allocations->first()?->salesOrder;

        return [
            'id' => $payment->getKey(),
            'payment_number' => $payment->payment_number,
            'status' => $payment->status->value,
            'amount' => (string) $payment->amount,
            'currency_code' => $payment->currency_code,
            'payment_date' => $payment->payment_date?->toDateString(),
            'method' => $payment->method->value,
            'financial_account' => $payment->financialAccount ? ['id' => $payment->financial_account_id, 'name' => $payment->financialAccount->name, 'code' => $payment->financialAccount->code] : null,
            'reference' => $payment->reference,
            'sales_order' => $order ? ['id' => $order->getKey(), 'order_number' => $order->order_number] : null,
            'customer' => $order ? ['id' => $order->customer_id, 'name' => $order->customer_name, 'company' => $order->customer_company] : null,
        ];
    }
}
