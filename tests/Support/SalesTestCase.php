<?php

namespace Tests\Support;

use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;

abstract class SalesTestCase extends InventoryTestCase
{
    protected function createCustomer(Organization $organization, string $name = 'Customer', array $attributes = []): Customer
    {
        $customer = new Customer;
        $customer->organization_id = $organization->getKey();
        $customer->type = $attributes['type'] ?? 'individual';
        $customer->display_name = $name;
        $customer->company_name = $attributes['company_name'] ?? null;
        $customer->email = $attributes['email'] ?? null;
        $customer->phone = $attributes['phone'] ?? null;
        $customer->tax_identifier = $attributes['tax_identifier'] ?? null;
        $customer->billing_address = $attributes['billing_address'] ?? null;
        $customer->notes = $attributes['notes'] ?? null;
        $customer->status = $attributes['status'] ?? 'active';
        $customer->save();

        return $customer;
    }

    protected function createDraftOrder(User $actor, Organization $organization, Store $store, ?Customer $customer = null, array $overrides = []): SalesOrder
    {
        $this->activate($actor, $organization, $store);

        return app(CreateSalesOrderAction::class)->execute($actor, $organization, $store, array_replace([
            'customer_id' => $customer?->getKey(), 'sale_date' => '2026-08-24',
            'currency_code' => 'MAD', 'notes' => 'Test order',
        ], $overrides));
    }

    protected function addCatalogLine(User $actor, SalesOrder $order, ProductVariant $variant, Warehouse $warehouse, array $overrides = []): SalesOrderLine
    {
        return app(SaveSalesOrderLineAction::class)->execute($actor, $order, array_replace([
            'line_type' => 'catalog', 'product_variant_id' => $variant->getKey(), 'warehouse_id' => $warehouse->getKey(),
            'quantity' => '1.0000', 'discount_type' => 'none', 'discount_value' => '0.0000',
        ], $overrides));
    }

    protected function addCustomLine(User $actor, SalesOrder $order, array $overrides = []): SalesOrderLine
    {
        return app(SaveSalesOrderLineAction::class)->execute($actor, $order, array_replace([
            'line_type' => 'custom', 'description' => 'Consulting', 'reference' => null, 'unit_label' => 'hour',
            'quantity' => '1.0000', 'unit_price_excl_tax' => '100.0000', 'tax_rate_id' => null,
            'discount_type' => 'none', 'discount_value' => '0.0000',
        ], $overrides));
    }

    protected function addDefaultSalesEmployee(Organization $organization, Store $store, User $user): void
    {
        $membership = new OrganizationMembership;
        $membership->organization_id = $organization->getKey();
        $membership->user_id = $user->getKey();
        $membership->role_id = $organization->roles()->where('slug', 'sales-employee')->firstOrFail()->getKey();
        $membership->status = 'active';
        $membership->save();
        $this->addStoreMember($store, $user);
        $this->activate($user, $organization, $store);
    }
}
