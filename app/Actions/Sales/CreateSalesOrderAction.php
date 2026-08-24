<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Enums\CustomerStatus;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\SalesOrderPaymentStatus;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SalesOrderNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateSalesOrderAction
{
    use AuthorizesSalesAction;

    public function __construct(private readonly SalesOrderNumberGenerator $numbers, private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Organization $organization, Store $store, array $data): SalesOrder
    {
        $this->authorizeSales($actor, $organization, $store, 'sales_orders.create');

        return DB::transaction(function () use ($actor, $organization, $store, $data) {
            $customer = $this->customer($organization, $data['customer_id'] ?? null);
            $order = new SalesOrder;
            $order->organization_id = $organization->getKey();
            $order->store_id = $store->getKey();
            $order->customer_id = $customer?->getKey();
            $order->order_number = $this->numbers->next($organization);
            $order->source = SalesOrderSource::Manual;
            $order->status = SalesOrderStatus::Draft;
            $order->fulfillment_status = SalesOrderFulfillmentStatus::Unfulfilled;
            $order->payment_status = SalesOrderPaymentStatus::Unpaid;
            $order->currency_code = strtoupper($data['currency_code'] ?? config('platform.currency_code', 'MAD'));
            $order->ordered_at = now();
            $order->sale_date = $data['sale_date'];
            $order->notes = $data['notes'] ?? null;
            $order->subtotal_excl_tax = '0.0000';
            $order->discount_total = '0.0000';
            $order->tax_total = '0.0000';
            $order->total_incl_tax = '0.0000';
            $order->created_by_user_id = $actor->getKey();
            $this->snapshotCustomer($order, $customer);
            $order->save();
            $this->audit->record('sales_order.created', $actor, $organization, $store, $order, newValues: [
                'order_number' => $order->order_number, 'store_id' => $store->getKey(),
                'customer_id' => $customer?->getKey(), 'sale_date' => $order->sale_date->toDateString(),
                'currency_code' => $order->currency_code, 'status' => SalesOrderStatus::Draft->value,
            ]);

            return $order;
        });
    }

    private function customer(Organization $organization, mixed $customerId): ?Customer
    {
        if (! $customerId) {
            return null;
        }
        $customer = Customer::query()->where('organization_id', $organization->getKey())->whereKey($customerId)->firstOrFail();
        if ($customer->status !== CustomerStatus::Active) {
            throw ValidationException::withMessages(['customer_id' => 'Only an active customer can be assigned to a new order.']);
        }

        return $customer;
    }

    private function snapshotCustomer(SalesOrder $order, ?Customer $customer): void
    {
        $order->customer_name = $customer?->display_name;
        $order->customer_company = $customer?->company_name;
        $order->customer_email = $customer?->email;
        $order->customer_phone = $customer?->phone;
    }
}
