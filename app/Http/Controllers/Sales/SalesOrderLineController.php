<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\RemoveSalesOrderLineAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesOrderRules;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        return SalesOrderRules::line();
    }
}
