<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\SalesOrderTotalsCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveSalesOrderLineAction
{
    use AuthorizesSalesAction;

    public function __construct(private readonly SalesOrderTotalsCalculator $totals) {}

    public function execute(User $actor, SalesOrder $order, SalesOrderLine $line): void
    {
        $this->authorizeOrder($actor, $order, 'sales_orders.update');
        DB::transaction(function () use ($order, $line) {
            $order = SalesOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($order->status !== SalesOrderStatus::Draft) {
                throw ValidationException::withMessages(['order' => 'Lines cannot be removed after confirmation.']);
            }
            $line = SalesOrderLine::query()->where('sales_order_id', $order->getKey())->whereKey($line->getKey())->firstOrFail();
            if ($line->procurements()->whereNotIn('status', ['cancelled', 'unavailable'])->exists()) {
                throw ValidationException::withMessages([
                    'line' => 'Annulez d’abord l’approvisionnement fournisseur lié à cette ligne.',
                ]);
            }
            $line->allocations()->delete();
            $line->delete();
            $this->totals->recalculate($order);
        });
    }
}
