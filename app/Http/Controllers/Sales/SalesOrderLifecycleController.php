<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Services\Pos\PosDraftLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $action->execute($request->user(), $order, $data['reason']);

        return back();
    }

    /**
     * "Supprimer" for a cancelled POS draft. Allowed only when it was never confirmed and carries no
     * commercial history (payments, invoices, fulfilments, procurements...); revalidated under lock.
     * Anything else stays cancelled (visible under « Annulées ») and cannot be hard-deleted.
     */
    public function destroyCancelledPosDraft(Request $request, SalesOrder $order, PosDraftLifecycle $lifecycle): RedirectResponse
    {
        $this->authorize('deleteCancelledPosDraft', $order);
        $result = $lifecycle->discard($request->user(), $order, PosDraftLifecycle::REASON_CANCELLED_DELETE);

        return match ($result['outcome']) {
            PosDraftLifecycle::OUTCOME_DISCARDED => redirect()->route('sales.orders.index')->with('success', 'Commande supprimée.'),
            PosDraftLifecycle::OUTCOME_SKIPPED_GONE => redirect()->route('sales.orders.index'),
            default => throw ValidationException::withMessages([
                'order' => 'Suppression impossible : cette commande a un historique commercial ou financier'
                    .($result['reasons'] ?? [] ? ' ('.implode(', ', $result['reasons']).')' : '').'. Elle reste archivée dans « Annulées ».',
            ]),
        };
    }

    public function fulfill(Request $request, SalesOrder $order, FulfillSalesOrderAction $action): RedirectResponse
    {
        $this->authorize('fulfill', $order);
        $action->execute($request->user(), $order);

        return back();
    }
}
