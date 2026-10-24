<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Rules\ValidWhatsAppUsername;
use App\Support\WhatsAppUsername;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Authoritative customer form rules, shared by the web customer form and MCP.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CustomerType::class)], 'display_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'], 'tax_identifier' => ['nullable', 'string', 'max:128'],
            'whatsapp_username' => ['nullable', 'string', new ValidWhatsAppUsername],
            'billing_address' => ['nullable', 'string', 'max:5000'], 'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::enum(CustomerStatus::class)],
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, Organization $organization, array $data): Customer
    {
        return DB::transaction(function () use ($actor, $organization, $data) {
            $customer = new Customer;
            $customer->organization_id = $organization->getKey();
            $this->apply($customer, $data);
            $customer->save();
            $this->audit->record('customer.created', $actor, $organization, auditable: $customer, newValues: $customer->only(['type', 'display_name', 'company_name', 'whatsapp_username', 'status']));

            return $customer;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, Customer $customer, array $data): Customer
    {
        return DB::transaction(function () use ($actor, $customer, $data) {
            $old = $customer->only(['type', 'display_name', 'company_name', 'whatsapp_username', 'status']);
            $this->apply($customer, $data);
            $customer->save();
            $this->audit->record('customer.updated', $actor, $customer->organization, auditable: $customer, oldValues: $old, newValues: $customer->only(['type', 'display_name', 'company_name', 'whatsapp_username', 'status']));

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
        if (array_key_exists('whatsapp_username', $data) || ! $customer->exists) {
            $customer->whatsapp_username = WhatsAppUsername::normalize($data['whatsapp_username'] ?? null);
        }
        $customer->tax_identifier = $data['tax_identifier'] ?? null;
        $customer->billing_address = $data['billing_address'] ?? null;
        $customer->notes = $data['notes'] ?? null;
        $customer->status = $data['status'] ?? CustomerStatus::Active->value;
    }
}
