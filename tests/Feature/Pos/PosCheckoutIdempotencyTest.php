<?php

namespace Tests\Feature\Pos;

use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\PosTestCase;

class PosCheckoutIdempotencyTest extends PosTestCase
{
    public function test_duplicate_split_checkout_submission_creates_one_logical_result(): void
    {
        [$owner, $organization, $warehouse, $variant, $cash] = $this->context();
        $card = $this->createPosAccount($organization, 'card_clearing', 'TPE', 'TPE');
        $operation = (string) Str::uuid();
        $payload = $this->posPayload($warehouse, [$this->posCatalogLine($variant)], [
            'client_operation_id' => $operation,
            'payments' => [
                $this->posPayment($cash, '40.0000'),
                $this->posPayment($card, '60.0000', ['method' => 'card', 'cash_received' => null]),
            ],
        ]);
        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)->assertRedirect();
        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)->assertRedirect();

        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('payment_allocations', 2);
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', 'reservation_consumed')->count());
        $this->assertSame('9.0000', $this->balance($organization, $warehouse, $variant)->on_hand);
    }

    public function test_changed_payment_payload_with_same_operation_id_is_rejected(): void
    {
        [$owner, , $warehouse, $variant] = $this->context();
        $operation = (string) Str::uuid();
        $payload = $this->posPayload($warehouse, [$this->posCatalogLine($variant)], ['client_operation_id' => $operation]);
        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)->assertRedirect();
        $changed = $payload;
        $changed['payments'][0]['cash_received'] = '120.0000';
        $this->actingAs($owner)->postJson(route('pos.sales.store'), $changed)->assertUnprocessable()->assertJsonValidationErrors('client_operation_id');

        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('100.0000', Payment::query()->firstOrFail()->amount);
    }

    public function test_changed_cart_with_same_operation_id_is_rejected(): void
    {
        [$owner, , $warehouse, $variant] = $this->context();
        $operation = (string) Str::uuid();
        $payload = $this->posPayload($warehouse, [$this->posCatalogLine($variant)], ['client_operation_id' => $operation]);
        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)->assertRedirect();
        $changed = $this->posPayload($warehouse, [$this->posCatalogLine($variant, ['quantity' => '2.0000'])], ['client_operation_id' => $operation]);
        $this->actingAs($owner)->postJson(route('pos.sales.store'), $changed)->assertUnprocessable()->assertJsonValidationErrors('client_operation_id');

        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertSame('1.0000', SalesOrder::query()->firstOrFail()->lines()->firstOrFail()->quantity);
    }

    private function context(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Retry Product', 'RETRY', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');
        $cash = $this->createPosAccount($organization, 'cash', 'CASH', 'Cash');

        return [$owner, $organization, $warehouse, $variant, $cash];
    }
}
