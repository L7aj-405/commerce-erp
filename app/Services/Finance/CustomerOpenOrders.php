<?php

namespace App\Services\Finance;

use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Read-only lookups shared by every surface that allocates a customer advance (customer account and the advances register):
 * the confirmed orders of the viewer's ACTIVE store that still have a balance, and the outstanding opening-balance lines.
 * No financial rule lives here: the allocation itself is always ManageCustomerAdvanceAction.
 */
class CustomerOpenOrders
{
    public function __construct(private readonly SalesOrderPaymentCalculator $calculator) {}

    /** @return list<array{id:int, order_number:string, sale_date:string, due_date:?string, total_incl_tax:string, remaining:string, is_overdue:bool}> */
    public function forCustomer(Customer $customer, User $user): array
    {
        if ($user->active_store_id === null) {
            return [];
        }
        $out = [];
        foreach (SalesOrder::query()->where('organization_id', $customer->organization_id)->where('store_id', $user->active_store_id)->where('customer_id', $customer->getKey())
            ->where('status', 'confirmed')->whereNull('sandbox_id')->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->orderBy('id')->limit(200)->get() as $order) {
            $remaining = $this->calculator->remainingAmount($order);
            if (Decimal::compare($remaining, '0') > 0) {
                $out[] = ['id' => $order->id, 'order_number' => $order->order_number, 'sale_date' => $order->sale_date->toDateString(), 'due_date' => $order->due_date?->toDateString(),
                    'total_incl_tax' => (string) $order->total_incl_tax, 'remaining' => $remaining, 'is_overdue' => $order->due_date !== null && $order->due_date->toDateString() < now()->toDateString()];
            }
        }

        return $out;
    }

    /** @return list<array{id:int, reference:?string, currency_code:string, outstanding:string}> */
    public function openingTargets(int $organizationId, int $customerId): array
    {
        return DB::table('customer_opening_balances as o')->where('o.organization_id', $organizationId)->where('o.customer_id', $customerId)
            ->where('o.status', 'posted')->where('o.amount', '>', 0)
            ->selectRaw('o.id, o.reference, o.currency_code, ROUND(o.amount - COALESCE((SELECT SUM(s.amount) FROM customer_opening_balance_settlements s WHERE s.organization_id = o.organization_id'
                .' AND s.opening_balance_id = o.id AND s.released_date IS NULL), 0), 4) as outstanding')->get()
            ->filter(fn ($o) => Decimal::compare(Decimal::normalize((string) $o->outstanding), '0') > 0)
            ->map(fn ($o) => ['id' => (int) $o->id, 'reference' => $o->reference, 'currency_code' => $o->currency_code, 'outstanding' => Decimal::normalize((string) $o->outstanding)])->values()->all();
    }
}
