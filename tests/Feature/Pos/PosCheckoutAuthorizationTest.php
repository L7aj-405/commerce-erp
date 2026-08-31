<?php

namespace Tests\Feature\Pos;

use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PosTestCase;

class PosCheckoutAuthorizationTest extends PosTestCase
{
    public function test_sales_employee_with_payment_permission_can_complete_paid_checkout(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->context();
        $employee = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $employee);
        $payload = $this->posPayload($warehouse, [$this->posCustomLine()]);
        $payload['payments'][0]['payment_date'] = now()->subMonth()->toDateString();
        $this->actingAs($employee)->post(route('pos.sales.store'), $payload)->assertRedirect();
        $payment = Payment::query()->firstOrFail();
        $this->assertSame($employee->id, $payment->received_by_user_id);
        $this->assertSame(now()->toDateString(), $payment->payment_date->toDateString());
        $this->assertSame('paid', $payment->allocations()->firstOrFail()->salesOrder->payment_status->value);
    }

    public function test_pos_user_without_payments_create_cannot_complete_checkout(): void
    {
        [, $organization, $store, $warehouse] = $this->context();
        $employee = User::factory()->create();
        $this->addOrganizationMember($organization, $employee, [
            'pos.access', 'sales_orders.create', 'sales_orders.update', 'sales_orders.confirm', 'sales_orders.fulfill',
        ], roleName: 'POS Without Payments');
        $this->addStoreMember($store, $employee);
        $this->activate($employee, $organization, $store);
        $this->actingAs($employee)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->where('can.createPayment', false)
            ->has('financialAccounts', 0));
        $this->actingAs($employee)->post(route('pos.sales.store'), $this->posPayload($warehouse, [$this->posCustomLine()]))->assertForbidden();
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_pos_checkout_forces_today_and_ignores_forged_payment_authority_fields(): void
    {
        [$owner, , , $warehouse] = $this->context();
        $payload = $this->posPayload($warehouse, [$this->posCustomLine()]);
        $payload['payments'][0] += [
            'payment_date' => now()->subYear()->toDateString(),
            'payment_number' => 'EVIL',
            'status' => 'reversed',
            'received_by_user_id' => 999999,
            'reversed_by_user_id' => 999999,
            'allocation_amount' => '0.0001',
        ];
        $payload += [
            'organization_id' => 999999, 'store_id' => 999999, 'payment_status' => 'unpaid',
            'fulfillment_status' => 'unfulfilled', 'source' => 'manual', 'sales_order_id' => 999999,
        ];
        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)->assertRedirect();

        $payment = Payment::query()->firstOrFail();
        $this->assertSame(now()->toDateString(), $payment->payment_date->toDateString());
        $this->assertSame('PAY-000001', $payment->payment_number);
        $this->assertSame('posted', $payment->status->value);
        $this->assertSame($owner->id, $payment->received_by_user_id);
        $this->assertNull($payment->reversed_by_user_id);
        $this->assertSame($payment->amount, $payment->allocations()->firstOrFail()->amount);
        $this->assertNotSame(999999, $payment->allocations()->firstOrFail()->sales_order_id);
    }

    private function context(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $this->activate($owner, $organization, $store);

        return [$owner, $organization, $store, $warehouse];
    }
}
