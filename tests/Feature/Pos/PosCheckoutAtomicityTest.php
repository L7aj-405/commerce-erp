<?php

namespace Tests\Feature\Pos;

use App\Actions\Sales\FulfillSalesOrderAction;
use App\Models\FinancialAccount;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\PosTestCase;

class PosCheckoutAtomicityTest extends PosTestCase
{
    public function test_invalid_second_split_payment_rolls_back_order_first_payment_and_reservation(): void
    {
        [$owner, $organization, $warehouse, $variant, $cash] = $this->stockContext();
        $bank = $this->createPosAccount($organization, 'bank', 'BANK', 'Bank');
        $payload = $this->posPayload($warehouse, [$this->posCatalogLine($variant)], ['payments' => [
            $this->posPayment($cash, '40.0000'),
            $this->posPayment($bank, '60.0000', ['method' => 'cash']),
        ]]);
        $this->actingAs($owner)->postJson(route('pos.sales.store'), $payload)->assertUnprocessable();
        $this->assertNoCheckoutMutation($organization, $warehouse, $variant);
    }

    public function test_fulfillment_failure_rolls_back_posted_payment_and_new_order(): void
    {
        [$owner, $organization, $warehouse, $variant] = $this->stockContext();
        $fulfillment = Mockery::mock(FulfillSalesOrderAction::class);
        $fulfillment->shouldReceive('execute')->once()->andThrow(ValidationException::withMessages(['order' => 'Injected fulfillment failure.']));
        $this->app->instance(FulfillSalesOrderAction::class, $fulfillment);

        $this->actingAs($owner)->postJson(route('pos.sales.store'), $this->posPayload($warehouse, [$this->posCatalogLine($variant)]))
            ->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->assertNoCheckoutMutation($organization, $warehouse, $variant);
    }

    public function test_insufficient_inventory_leaves_no_payment_or_orphan_allocation(): void
    {
        [$owner, $organization, $warehouse, $variant] = $this->stockContext('0.5000');
        $this->actingAs($owner)->postJson(route('pos.sales.store'), $this->posPayload($warehouse, [$this->posCatalogLine($variant)]))->assertUnprocessable();
        $this->assertNoCheckoutMutation($organization, $warehouse, $variant, '0.5000');
    }

    public function test_incompatible_account_leaves_no_fulfillment_or_inventory_consumption(): void
    {
        [$owner, $organization, $warehouse, $variant] = $this->stockContext();
        $bank = $this->createPosAccount($organization, 'bank', 'BANK', 'Bank');
        $payload = $this->posPayload($warehouse, [$this->posCatalogLine($variant)], ['payments' => [
            $this->posPayment($bank, '100.0000', ['method' => 'cash']),
        ]]);
        $this->actingAs($owner)->postJson(route('pos.sales.store'), $payload)->assertUnprocessable();
        $this->assertNoCheckoutMutation($organization, $warehouse, $variant);
    }

    public function test_wrong_currency_account_rolls_back_checkout(): void
    {
        [$owner, $organization, $warehouse, $variant] = $this->stockContext();
        $euroCash = $this->createPosAccount($organization, 'cash', 'EUR-CASH', 'Euro Cash', 'EUR');
        $payload = $this->posPayload($warehouse, [$this->posCatalogLine($variant)], ['payments' => [
            $this->posPayment($euroCash, '100.0000'),
        ]]);
        $this->actingAs($owner)->postJson(route('pos.sales.store'), $payload)->assertUnprocessable();
        $this->assertNoCheckoutMutation($organization, $warehouse, $variant);
    }

    /** @return array{User, Organization, Warehouse, ProductVariant, FinancialAccount} */
    private function stockContext(string $stock = '10.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Atomic Product', 'ATOMIC', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, $stock);
        $cash = $this->createPosAccount($organization, 'cash', 'CASH', 'Cash');

        return [$owner, $organization, $warehouse, $variant, $cash];
    }

    private function assertNoCheckoutMutation($organization, $warehouse, $variant, string $onHand = '10.0000'): void
    {
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertSame($onHand, $this->balance($organization, $warehouse, $variant)->on_hand);
        $this->assertSame(0, InventoryMovement::query()->where('movement_type', 'reservation_consumed')->count());
    }
}
