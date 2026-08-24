<?php

namespace Tests\Feature\Sales;

use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderLine;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Tests\Support\SalesTestCase;

class SalesDatabaseIntegrityTest extends SalesTestCase
{
    public function test_order_store_and_customer_composite_foreign_keys_reject_cross_tenant_rows(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA);
        $organizationB = $this->createOrganization($ownerB);
        $storeA = $this->createStore($organizationA, $ownerA);
        $storeB = $this->createStore($organizationB, $ownerB);
        $customerB = $this->createCustomer($organizationB);
        foreach ([['store_id' => $storeB->id, 'customer_id' => null], ['store_id' => $storeA->id, 'customer_id' => $customerB->id]] as $relation) {
            try {
                $this->rawOrder($organizationA->id, $relation['store_id'], $relation['customer_id']);
                $this->fail('Expected cross-tenant order rejection.');
            } catch (QueryException) {
                $this->assertDatabaseCount('sales_orders', 0);
            }
        }
    }

    public function test_line_variant_composite_foreign_key_rejects_cross_tenant_variant(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA);
        $storeA = $this->createStore($organizationA, $ownerA);
        $orderA = $this->createDraftOrder($ownerA, $organizationA, $storeA);
        $organizationB = $this->createOrganization($ownerB);
        $variantB = $this->createProduct($organizationB)->variants->first();
        $line = new SalesOrderLine;
        $line->organization_id = $organizationA->id;
        $line->sales_order_id = $orderA->id;
        $line->line_type = 'catalog';
        $line->position = 1;
        $line->product_variant_id = $variantB->id;
        $line->product_name = 'Attack';
        $line->quantity = '1';
        $line->unit_price_excl_tax = '1';
        $line->tax_rate = '0';
        $line->discount_type = 'none';
        $line->discount_value = '0';
        $line->subtotal_excl_tax = '1';
        $line->discount_amount = '0';
        $line->taxable_amount = '1';
        $line->tax_amount = '0';
        $line->total_incl_tax = '1';
        $this->expectException(QueryException::class);
        $line->save();
    }

    public function test_allocation_composite_foreign_keys_reject_foreign_warehouse_and_reservation(): void
    {
        $ownerA = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA);
        $storeA = $this->createStore($organizationA, $ownerA);
        $warehouseA = $this->createWarehouse($organizationA);
        $orderA = $this->createDraftOrder($ownerA, $organizationA, $storeA);
        $lineA = $this->addCustomLine($ownerA, $orderA);
        $ownerB = User::factory()->create();
        $organizationB = $this->createOrganization($ownerB);
        $warehouseB = $this->createWarehouse($organizationB);
        $variantB = $this->createProduct($organizationB)->variants->first();
        $this->openStock($ownerB, $organizationB, $warehouseB, $variantB);
        $reservationB = $this->reserve($ownerB, $organizationB, $warehouseB, $variantB);
        $allocation = new SalesOrderInventoryAllocation;
        $allocation->organization_id = $organizationA->id;
        $allocation->sales_order_line_id = $lineA->id;
        $allocation->warehouse_id = $warehouseB->id;
        $allocation->quantity = '1';
        $allocation->inventory_reservation_id = $reservationB->id;
        $this->expectException(QueryException::class);
        $allocation->save();
    }

    public function test_sales_models_are_fully_guarded(): void
    {
        $this->expectException(MassAssignmentException::class);
        SalesOrder::query()->create(['organization_id' => 999, 'store_id' => 999, 'status' => 'confirmed', 'total_incl_tax' => '999.0000']);
    }

    public function test_order_number_collision_is_rejected_by_organization_unique_constraint(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $order = $this->createDraftOrder($owner, $organization, $store);

        $duplicate = $order->replicate();
        $duplicate->created_at = null;
        $duplicate->updated_at = null;
        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    private function rawOrder(int $organizationId, int $storeId, ?int $customerId): void
    {
        $order = new SalesOrder;
        $order->organization_id = $organizationId;
        $order->store_id = $storeId;
        $order->customer_id = $customerId;
        $order->order_number = 'ATTACK-'.uniqid();
        $order->source = 'manual';
        $order->status = 'draft';
        $order->fulfillment_status = 'unfulfilled';
        $order->payment_status = 'unpaid';
        $order->currency_code = 'MAD';
        $order->ordered_at = now();
        $order->sale_date = now();
        $order->subtotal_excl_tax = 0;
        $order->discount_total = 0;
        $order->tax_total = 0;
        $order->total_incl_tax = 0;
        $order->save();
    }
}
