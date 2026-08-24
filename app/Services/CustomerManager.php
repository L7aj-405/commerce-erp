<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CustomerManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, Organization $organization, array $data): Customer
    {
        return DB::transaction(function () use ($actor, $organization, $data) {
            $customer = new Customer;
            $customer->organization_id = $organization->getKey();
            $this->apply($customer, $data);
            $customer->save();
            $this->audit->record('customer.created', $actor, $organization, auditable: $customer, newValues: $customer->only(['type', 'display_name', 'company_name', 'status']));

            return $customer;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, Customer $customer, array $data): Customer
    {
        return DB::transaction(function () use ($actor, $customer, $data) {
            $old = $customer->only(['type', 'display_name', 'company_name', 'status']);
            $this->apply($customer, $data);
            $customer->save();
            $this->audit->record('customer.updated', $actor, $customer->organization, auditable: $customer, oldValues: $old, newValues: $customer->only(['type', 'display_name', 'company_name', 'status']));

            return $customer;
        });
    }

    /** @param array<string, mixed> $data */
    private function apply(Customer $customer, array $data): void
    {
        $customer->type = $data['type'];
        $customer->display_name = $data['display_name'];
        $customer->company_name = $data['company_name'] ?? null;
        $customer->email = $data['email'] ?? null;
        $customer->phone = $data['phone'] ?? null;
        $customer->tax_identifier = $data['tax_identifier'] ?? null;
        $customer->billing_address = $data['billing_address'] ?? null;
        $customer->notes = $data['notes'] ?? null;
        $customer->status = $data['status'] ?? CustomerStatus::Active->value;
    }
}
