<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\RemoveSalesOrderLineAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Enums\SalesOrderDiscountType;
use App\Enums\SalesOrderLineType;
use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesOrderLineController extends Controller
{
    public function store(Request $request, SalesOrder $order, SaveSalesOrderLineAction $action): RedirectResponse
    {
        $this->authorize('update', $order);
        $action->execute($request->user(), $order, $request->validate($this->rules()));

        return back();
    }

    public function update(Request $request, SalesOrder $order, int $lineId, SaveSalesOrderLineAction $action): RedirectResponse
    {
        $this->authorize('update', $order);
        $line = SalesOrderLine::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())->whereKey($lineId)->firstOrFail();
        $action->execute($request->user(), $order, $request->validate($this->rules()), $line);

        return back();
    }

    public function destroy(Request $request, SalesOrder $order, int $lineId, RemoveSalesOrderLineAction $action): RedirectResponse
    {
        $this->authorize('update', $order);
        $line = SalesOrderLine::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())->whereKey($lineId)->firstOrFail();
        $action->execute($request->user(), $order, $line);

        return back();
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'line_type' => ['required', Rule::enum(SalesOrderLineType::class)],
            'product_variant_id' => ['nullable', 'required_if:line_type,catalog', 'integer'],
            'warehouse_id' => ['nullable', 'required_if:line_type,catalog', 'integer'],
            'description' => ['nullable', 'required_if:line_type,custom', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'], 'unit_label' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'unit_price_excl_tax' => ['nullable', 'required_if:line_type,custom', 'decimal:0,4', 'gte:0'],
            'tax_rate_id' => ['nullable', 'integer'],
            'discount_type' => ['required', Rule::enum(SalesOrderDiscountType::class)],
            'discount_value' => ['nullable', 'decimal:0,4', 'gte:0'],
        ];
    }
}
