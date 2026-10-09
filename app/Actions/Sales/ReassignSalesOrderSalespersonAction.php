<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SalespersonEligibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReassignSalesOrderSalespersonAction
{
    use AuthorizesSalesAction;

    public function __construct(
        private readonly SalespersonEligibilityService $salespersons,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, SalesOrder $order, ?int $salespersonId, string $reason): SalesOrder
    {
        $this->authorizeOrder($actor, $order, 'sales_orders.reassign_salesperson');

        return DB::transaction(function () use ($actor, $order, $salespersonId, $reason) {
            $order = SalesOrder::query()
                ->where('organization_id', $order->organization_id)
                ->where('store_id', $order->store_id)
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->with(['organization', 'store'])
                ->firstOrFail();

            if ($order->status !== SalesOrderStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'order' => 'La réattribution dédiée est réservée aux commandes confirmées.',
                ]);
            }

            $salesperson = $this->salespersons->resolve($order->organization_id, $salespersonId);
            $old = ['salesperson_id' => $order->salesperson_id, 'salesperson_name' => $order->salesperson_name_snapshot];
            $order->salesperson_id = $salesperson?->getKey();
            $order->salesperson_name_snapshot = $salesperson?->name;
            $order->save();

            $this->audit->record('sales.salesperson_reassigned', $actor, $order->organization, $order->store, $order,
                oldValues: $old,
                newValues: [
                    'salesperson_id' => $order->salesperson_id,
                    'salesperson_name' => $order->salesperson_name_snapshot,
                    'reason' => $reason,
                ],
            );

            return $order->fresh('salesperson:id,name');
        });
    }
}
