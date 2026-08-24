<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SalesOrderLifecycleController extends Controller
{
    public function confirm(Request $request, SalesOrder $order, ConfirmSalesOrderAction $action): RedirectResponse
    {
        $this->authorize('confirm', $order);
        $action->execute($request->user(), $order);

        return redirect()->route('sales.orders.show', $order);
    }

    public function cancel(Request $request, SalesOrder $order, CancelSalesOrderAction $action): RedirectResponse
    {
        $this->authorize('cancel', $order);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $action->execute($request->user(), $order, $data['reason'] ?? null);

        return back();
    }

    public function fulfill(Request $request, SalesOrder $order, FulfillSalesOrderAction $action): RedirectResponse
    {
        $this->authorize('fulfill', $order);
        $action->execute($request->user(), $order);

        return back();
    }
}
