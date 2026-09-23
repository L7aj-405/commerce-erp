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

    /**
     * The modern editor no longer sends a per-line `warehouse_id` (sourcing is
     * resolved server-side) and lets a custom line's price be entered HT or TTC
     * (`price_input_mode` + `unit_price`). The legacy `unit_price_excl_tax` HT
     * field is still accepted for backward compatibility (Devis conversion, API).
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'line_type' => ['required', Rule::enum(SalesOrderLineType::class)],
            'product_variant_id' => ['nullable', 'required_if:line_type,catalog', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'required_if:line_type,custom', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'], 'unit_label' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'regex:/^[1-9]\d*(?:\.0{1,4})?$/'],
            'price_input_mode' => ['nullable', 'in:ht,ttc'],
            'unit_price' => ['nullable', 'decimal:0,4', 'gte:0'],
            'unit_price_excl_tax' => ['nullable', 'decimal:0,4', 'gte:0'],
            'tax_rate_id' => ['nullable', 'integer'],
            'discount_type' => ['required', Rule::enum(SalesOrderDiscountType::class)],
            'discount_value' => ['nullable', 'decimal:0,4', 'gte:0'],
        ];
    }
}
