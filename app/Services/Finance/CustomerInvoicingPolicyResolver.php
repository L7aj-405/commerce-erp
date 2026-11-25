<?php

namespace App\Services\Finance;

use App\Enums\CustomerInvoicingPolicy;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * OPT-FC-004: the ONE authoritative evaluation of the customer invoicing policy.
 *
 * Effective policy = the explicit value saved in customer_financial_terms.invoicing_policy, otherwise the
 * default derived from the customer type (Company = before payment allowed, Individual / no customer = after
 * full payment). Nothing is backfilled: existing customers simply resolve through this method.
 * Only the payment requirement is configurable; every other invoicing rule stays in the actions.
 */
class CustomerInvoicingPolicyResolver
{
    public function forCustomer(?Customer $customer): CustomerInvoicingPolicy
    {
        if ($customer === null) {
            return CustomerInvoicingPolicy::AfterFullPayment;
        }
        $explicit = DB::table('customer_financial_terms')
            ->where('organization_id', $customer->organization_id)->where('customer_id', $customer->getKey())
            ->value('invoicing_policy');

        return CustomerInvoicingPolicy::tryFrom((string) $explicit) ?? CustomerInvoicingPolicy::defaultFor($customer->type);
    }

    public function forOrder(SalesOrder $order): CustomerInvoicingPolicy
    {
        return $this->forCustomer($order->customer_id ? ($order->relationLoaded('customer') ? $order->customer : $order->customer()->first()) : null);
    }

    /** True when an invoice may be issued although $remaining is still due on the order. */
    public function allowsIssuance(SalesOrder $order, string $remaining): bool
    {
        if (Decimal::compare($remaining, '0.0000') <= 0) {
            return true;
        }

        return $this->forOrder($order) === CustomerInvoicingPolicy::BeforePaymentAllowed;
    }
}
