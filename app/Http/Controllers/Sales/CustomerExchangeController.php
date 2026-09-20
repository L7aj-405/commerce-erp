<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Exchanges\CancelCustomerExchangeAction;
use App\Actions\Exchanges\CreateCustomerExchangeAction;
use App\Actions\Exchanges\FulfillCustomerExchangeReplacementAction;
use App\Actions\Exchanges\ReceiveCustomerExchangeReturnAction;
use App\Actions\Exchanges\SettleCustomerExchangePaymentAction;
use App\Actions\Exchanges\SettleCustomerExchangeRefundAction;
use App\Enums\CatalogStatus;
use App\Enums\PaymentStatus;
use App\Enums\WarehouseStatus;
use App\Http\Controllers\Controller;
use App\Models\CustomerExchange;
use App\Models\CustomerReturnLine;
use App\Models\FinancialAccount;
use App\Models\InventoryBalance;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Services\ProductPriceResolver;
use App\Services\ReturnPolicyService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerExchangeController extends Controller
{
    public function create(Request $request, SalesOrder $order, ReturnPolicyService $policies): Response
    {
        $this->authorize('create', [CustomerExchange::class, $order]);
        $order->load(['organization', 'store', 'lines']);
        $accepted = CustomerReturnLine::query()->where('organization_id', $order->organization_id)->whereIn('sales_order_line_id', $order->lines->pluck('id'))
            ->whereHas('customerReturn', fn ($query) => $query->whereIn('status', ['draft', 'received']))
            ->selectRaw('sales_order_line_id, SUM(quantity) as quantity')->groupBy('sales_order_line_id')->pluck('quantity', 'sales_order_line_id');

        return Inertia::render('Sales/Exchanges/Create', [
            'order' => $order->only(['id', 'order_number', 'customer_name', 'fulfilled_at', 'currency_code']),
            'policy' => $policies->evaluate($order),
            'lines' => $order->lines->whereNotNull('product_variant_id')->map(fn ($line) => [
                ...$line->only(['id', 'product_variant_id', 'product_name', 'variant_name', 'sku', 'reference', 'unit_label', 'quantity', 'unit_price_incl_tax', 'total_incl_tax']),
                'already_returned' => (string) ($accepted[$line->id] ?? '0.0000'),
                'returnable' => Decimal::subtract($line->quantity, (string) ($accepted[$line->id] ?? '0.0000')),
            ])->values(),
            'canOverride' => $request->user()->hasPermission($order->organization_id, 'sales_returns.override_policy'),
            'searchUrl' => route('sales.orders.exchanges.search', $order),
            'submitUrl' => route('sales.orders.exchanges.store', $order),
        ]);
    }

    public function store(Request $request, SalesOrder $order, CreateCustomerExchangeAction $action): RedirectResponse
    {
        $this->authorize('create', [CustomerExchange::class, $order]);
        $data = $request->validate([
            'client_operation_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:2000'],
            'disposition' => ['required', 'in:restock,damaged'], 'override_policy' => ['sometimes', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:2000'],
            'returned_items' => ['required', 'array', 'min:1', 'max:50'],
            'returned_items.*.sales_order_line_id' => ['required', 'integer', 'distinct'],
            'returned_items.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'replacement_items' => ['required', 'array', 'min:1', 'max:50'],
            'replacement_items.*.product_variant_id' => ['required', 'integer', 'distinct'],
            'replacement_items.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
        ]);
        $exchange = $action->execute($request->user(), $order, $data['client_operation_id'], $data['returned_items'], $data['replacement_items'], $data['reason'], $data['disposition'], (bool) ($data['override_policy'] ?? false), $data['override_reason'] ?? null);

        return redirect()->route('sales.exchanges.show', $exchange)->with('success', 'Échange créé. Réceptionnez les articles retournés avant de remettre les nouveaux articles.');
    }

    public function show(CustomerExchange $exchange): Response
    {
        $this->authorize('view', $exchange);
        $exchange->load(['salesOrder:id,order_number,currency_code', 'customerReturn.lines', 'customerReturn.creditNotes', 'customerReturn.refunds', 'salesOrderAddendum.lines', 'payments']);
        $plannedVariants = ProductVariant::query()->where('organization_id', $exchange->organization_id)
            ->whereIn('id', collect($exchange->replacement_items)->pluck('product_variant_id'))->with('product:id,name')->get()->keyBy('id');
        $payments = Payment::query()->where('organization_id', $exchange->organization_id)->where('store_id', $exchange->store_id)
            ->where('status', PaymentStatus::Posted->value)->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $exchange->sales_order_id))
            ->with('financialAccount:id,name,code,type')->get(['id', 'payment_number', 'method', 'amount', 'financial_account_id']);
        $direction = Decimal::compare($exchange->difference_amount, '0.0000');
        $settledAmount = $direction > 0
            ? $exchange->payments->where('status', PaymentStatus::Posted)->reduce(fn (string $sum, $payment) => Decimal::add($sum, $payment->amount), '0.0000')
            : $exchange->customerReturn->refunds->where('status', 'posted')->reduce(fn (string $sum, $refund) => Decimal::add($sum, $refund->amount), '0.0000');
        $target = $direction < 0 ? Decimal::subtract('0.0000', $exchange->difference_amount) : $exchange->difference_amount;
        $remaining = Decimal::subtract($target, $settledAmount);
        if (Decimal::compare($remaining, '0.0000') < 0) $remaining = '0.0000';

        return Inertia::render('Sales/Exchanges/Show', [
            'exchange' => $exchange,
            'replacementItems' => collect($exchange->replacement_items)->map(function (array $item) use ($plannedVariants) {
                $variant = $plannedVariants->get((int) $item['product_variant_id']);
                return [...$item, 'product_name' => $variant?->product?->name ?? 'Article', 'variant_name' => $variant?->label,
                    'reference' => $variant?->reference ?? $variant?->sku];
            })->values(),
            'settlementSummary' => ['target' => $target, 'settled' => $settledAmount, 'remaining' => $remaining,
                'direction' => $direction > 0 ? 'payment' : ($direction < 0 ? 'refund' : 'zero')],
            'payments' => $payments,
            'financialAccounts' => FinancialAccount::query()->where('organization_id', $exchange->organization_id)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code', 'type', 'accepted_methods']),
            'can' => ['process' => request()->user()->can('process', $exchange), 'refund' => request()->user()->hasPermission($exchange->organization_id, 'payments.reverse')],
        ]);
    }

    public function search(Request $request, SalesOrder $order, ProductPriceResolver $prices): JsonResponse
    {
        $this->authorize('create', [CustomerExchange::class, $order]);
        $search = trim((string) ($request->validate(['search' => ['nullable', 'string', 'max:255']])['search'] ?? ''));
        if ($search === '') return response()->json(['data' => []]);
        $variants = ProductVariant::query()->where('organization_id', $order->organization_id)->where('status', CatalogStatus::Active->value)
            ->whereHas('product', fn ($query) => $query->where('status', CatalogStatus::Active->value))
            ->where(fn ($query) => $query->where('sku', 'like', "%{$search}%")->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%")))
            ->with(['product:id,name,default_unit_id', 'product.defaultUnit:id,name,symbol', 'taxRate:id,name,rate'])->limit(15)->get();
        $balances = InventoryBalance::query()->where('organization_id', $order->organization_id)->where('warehouse_id', $order->pos_warehouse_id)
            ->whereIn('product_variant_id', $variants->pluck('id'))->whereHas('warehouse', fn ($query) => $query->where('status', WarehouseStatus::Active->value))->get()->keyBy('product_variant_id');
        $defaultTax = $prices->defaultTaxRate($order->store, (int) $order->organization_id);

        return response()->json(['data' => $variants->map(function ($variant) use ($prices, $defaultTax, $balances) {
            $price = $prices->resolveWith($variant, $defaultTax);
            return ['id' => $variant->id, 'product_name' => $variant->product->name, 'variant_name' => $variant->label,
                'sku' => $variant->sku, 'reference' => $variant->reference, 'unit_price_incl_tax' => $price['unit_price_ttc'],
                'config_missing' => $price['config_missing'], 'local_stock_available' => $balances->get($variant->id)?->available ?? '0.0000'];
        })->values()]);
    }

    public function receive(Request $request, CustomerExchange $exchange, ReceiveCustomerExchangeReturnAction $action): RedirectResponse
    {
        $this->authorize('process', $exchange); $action->execute($request->user(), $exchange);
        return back()->with('success', 'Retour physique réceptionné.');
    }

    public function fulfill(Request $request, CustomerExchange $exchange, FulfillCustomerExchangeReplacementAction $action): RedirectResponse
    {
        $this->authorize('process', $exchange); $action->execute($request->user(), $exchange);
        return back()->with('success', 'Nouveaux articles remis depuis le stock local.');
    }

    public function pay(Request $request, CustomerExchange $exchange, SettleCustomerExchangePaymentAction $action): RedirectResponse
    {
        $this->authorize('process', $exchange);
        $data = $request->validate(['client_operation_id' => ['required', 'uuid'], 'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'in:cash,card,bank_transfer,cheque'], 'payments.*.financial_account_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'decimal:0,4', 'gt:0'], 'payments.*.payment_date' => ['required', 'date'],
            'payments.*.reference' => ['nullable', 'string', 'max:255']]);
        $action->execute($request->user(), $exchange, $data['payments'], $data['client_operation_id']);
        return back()->with('success', 'Paiement complémentaire enregistré.');
    }

    public function refund(Request $request, CustomerExchange $exchange, SettleCustomerExchangeRefundAction $action): RedirectResponse
    {
        $this->authorize('process', $exchange);
        $data = $request->validate(['client_operation_id' => ['required', 'uuid'], 'payment_id' => ['required', 'integer'], 'amount' => ['required', 'decimal:0,4', 'gt:0'], 'reason' => ['required', 'string', 'max:2000']]);
        $payment = Payment::query()->where('organization_id', $exchange->organization_id)->where('store_id', $exchange->store_id)->whereKey($data['payment_id'])->firstOrFail();
        $action->execute($request->user(), $exchange, $payment, $data['amount'], $data['reason'], $data['client_operation_id']);
        return back()->with('success', 'Remboursement enregistré.');
    }

    public function cancel(Request $request, CustomerExchange $exchange, CancelCustomerExchangeAction $action): RedirectResponse
    {
        $this->authorize('process', $exchange); $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $action->execute($request->user(), $exchange, $data['reason']);
        return back()->with('success', 'Échange annulé sans effacer son historique.');
    }
}
