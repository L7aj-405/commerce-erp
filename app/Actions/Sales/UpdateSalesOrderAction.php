<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateSalesOrderAction
{
    use AuthorizesSalesAction;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, SalesOrder $order, array $data): SalesOrder
    {
        $this->authorizeOrder($actor, $order, 'sales_orders.update');

        return DB::transaction(function () use ($actor, $order, $data) {
            $order = SalesOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if (! $order->isCommerciallyEditable()) {
                throw ValidationException::withMessages(['order' => 'Confirmed or cancelled orders are commercially immutable.']);
            }
            $customer = $data['customer_id'] ?? null
                ? Customer::query()->where('organization_id', $order->organization_id)->whereKey($data['customer_id'])->firstOrFail()
                : null;
            if ($customer && $customer->status !== CustomerStatus::Active) {
                throw ValidationException::withMessages(['customer_id' => 'Only an active customer can be assigned to a draft order.']);
            }
            $order->customer_id = $customer?->getKey();
            $order->customer_name = $customer?->display_name;
            $order->customer_company = $customer?->company_name;
            $order->customer_email = $customer?->email;
            $order->customer_phone = $customer?->phone;
            $order->sale_date = $data['sale_date'];
            $order->currency_code = strtoupper($data['currency_code']);
            $order->notes = $data['notes'] ?? null;
            $order->save();
            $this->audit->record('sales_order.updated', $actor, $order->organization, $order->store, $order, newValues: [
                'order_number' => $order->order_number, 'customer_id' => $customer?->getKey(),
                'sale_date' => $order->sale_date->toDateString(), 'currency_code' => $order->currency_code,
            ]);

            return $order;
        });
    }
}
