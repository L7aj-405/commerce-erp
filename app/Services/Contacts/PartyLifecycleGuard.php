<?php

namespace App\Services\Contacts;

use App\Actions\Finance\Customers\ManageCustomerAdvanceAction;
use App\Models\Customer;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Protected lifecycle rules. Archiving a party or deactivating a commercial role is a SOFT status change: profiles,
 * documents and financial rows are never deleted or edited. It is refused while money is still open:
 *
 *  customer: confirmed orders not fully paid, a non-zero posted opening balance, or an unreleased customer advance;
 *  supplier: a non-cancelled payment obligation whose planned amount is not fully allocated to outgoing payments.
 *
 * Reads are organization-wide (a lifecycle decision must not depend on the actor's store visibility).
 */
class PartyLifecycleGuard
{
    /** @return list<string> stable blocker codes; empty = the party may be archived / its commercial role deactivated */
    public function blockers(Party $party, ?string $only = null): array
    {
        $blockers = [];
        $customer = Customer::query()->where('organization_id', $party->organization_id)->where('party_id', $party->getKey())->first();
        if ($customer && ($only === null || $only === 'client')) {
            $blockers = [...$blockers, ...$this->customerBlockers($customer)];
        }
        $supplier = Supplier::query()->where('organization_id', $party->organization_id)->where('party_id', $party->getKey())->first();
        if ($supplier && ($only === null || $only === 'supplier')) {
            $blockers = [...$blockers, ...$this->supplierBlockers($supplier)];
        }

        return $blockers;
    }

    /** @return list<string> */
    private function customerBlockers(Customer $customer): array
    {
        $org = $customer->organization_id;
        $out = [];
        if (DB::table('sales_orders')->where('organization_id', $org)->where('customer_id', $customer->getKey())->whereNull('sandbox_id')
            ->where('status', 'confirmed')->where('payment_status', '!=', 'paid')->exists()) {
            $out[] = 'customer_unpaid_orders';
        }
        $opening = (string) DB::table('customer_opening_balances')->where('organization_id', $org)->where('customer_id', $customer->getKey())->where('status', 'posted')->sum('amount');
        if (bccomp($opening, '0', 4) !== 0) {
            $out[] = 'customer_opening_balance';
        }
        $advances = Payment::query()->where('organization_id', $org)->where('customer_id', $customer->getKey())->where('status', 'posted')->get();
        foreach ($advances as $payment) {
            if (bccomp(app(ManageCustomerAdvanceAction::class)->available($payment), '0', 4) > 0) {
                $out[] = 'customer_open_advance';
                break;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function supplierBlockers(Supplier $supplier): array
    {
        $open = DB::table('finance_obligations as o')
            ->where('o.organization_id', $supplier->organization_id)->where('o.supplier_id', $supplier->getKey())->whereNull('o.cancelled_at')
            ->whereRaw('o.planned_amount > COALESCE((SELECT SUM(a.amount) FROM finance_outgoing_payment_allocations a WHERE a.organization_id = o.organization_id AND a.obligation_id = o.id), 0)')
            ->exists();

        return $open ? ['supplier_open_obligations'] : [];
    }
}
