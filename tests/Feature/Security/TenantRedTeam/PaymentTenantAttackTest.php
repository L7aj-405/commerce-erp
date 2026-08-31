<?php

namespace Tests\Feature\Security\TenantRedTeam;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PaymentTestCase;

class PaymentTenantAttackTest extends PaymentTestCase
{
    public function test_foreign_organization_account_id_cannot_be_forged_into_payment(): void
    {
        [$attacker, , , $order] = $this->paymentFixture();
        $victim = User::factory()->create();
        $victimOrganization = $this->createOrganization($victim, 'Victim');
        $foreignAccount = $this->createFinancialAccount($victimOrganization, ['code' => 'VICTIM']);

        $this->actingAs($attacker)->post(route('sales.orders.payments.store', $order), $this->requestPayload($foreignAccount->id))->assertNotFound();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_foreign_organization_order_is_hidden_by_route_binding(): void
    {
        [$attacker, $attackerOrganization, $attackerStore] = $this->paymentFixture();
        [, , , $foreignOrder, $foreignAccount] = $this->paymentFixture();
        $this->activate($attacker, $attackerOrganization, $attackerStore);
        $this->actingAs($attacker)->post(route('sales.orders.payments.store', $foreignOrder), $this->requestPayload($foreignAccount->id))->assertNotFound();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_from_another_store_is_hidden_after_store_switch(): void
    {
        [$owner, $organization, $firstStore, , $account] = $this->paymentFixture();
        $secondStore = $this->createStore($organization, $owner, 'Second Store');
        $secondOrder = $this->createDraftOrder($owner, $organization, $secondStore);
        $this->addCustomLine($owner, $secondOrder);
        $secondOrder = app(ConfirmSalesOrderAction::class)->execute($owner, $secondOrder);
        $payment = $this->recordPayment($owner, $secondOrder, $account, '10.0000');
        $this->activate($owner, $organization, $firstStore);

        $this->actingAs($owner)->get(route('payments.show', $payment))->assertNotFound();
        $this->actingAs($owner)->post(route('sales.orders.payments.store', $secondOrder), $this->requestPayload($account->id))->assertNotFound();
        $this->actingAs($owner)->post(route('payments.reverse', $payment), ['reason' => 'Wrong store'])->assertNotFound();
    }

    public function test_browser_tenant_ids_are_ignored_and_server_derives_scope_from_order(): void
    {
        [$owner, $organization, $store, $order, $account] = $this->paymentFixture();
        $payload = $this->requestPayload($account->id);
        $payload['organization_id'] = 999999;
        $payload['store_id'] = 999999;
        $payload['payments'][0]['organization_id'] = 999999;
        $payload['payments'][0]['store_id'] = 999999;
        $this->actingAs($owner)->post(route('sales.orders.payments.store', $order), $payload)->assertRedirect(route('sales.orders.show', $order));
        $this->assertDatabaseHas('payments', ['organization_id' => $organization->id, 'store_id' => $store->id, 'amount' => 10]);
    }

    public function test_user_without_payment_permission_is_stopped_before_mutation(): void
    {
        [, $organization, $store, $order, $account] = $this->paymentFixture();
        $attacker = User::factory()->create();
        $this->addOrganizationMember($organization, $attacker, ['sales_orders.view']);
        $this->addStoreMember($store, $attacker);
        $this->activate($attacker, $organization, $store);
        $this->actingAs($attacker)->post(route('sales.orders.payments.store', $order), $this->requestPayload($account->id))->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_store_membership_is_required_even_when_permission_is_assigned(): void
    {
        [, $organization, $store, $order, $account] = $this->paymentFixture();
        $attacker = User::factory()->create();
        $this->addOrganizationMember($organization, $attacker, ['payments.create']);
        $this->activate($attacker, $organization, $store);
        // Organization membership plus payments.create is insufficient; without Store membership the Store-scoped SalesOrder is intentionally hidden by route binding.
        $this->actingAs($attacker)->post(route('sales.orders.payments.store', $order), $this->requestPayload($account->id))->assertNotFound();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_sales_employee_cannot_reverse_posted_payment(): void
    {
        [$owner, $organization, $store, $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '10.0000');
        $employee = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $employee);
        $this->actingAs($employee)->post(route('payments.reverse', $payment), ['reason' => 'Attack'])->assertForbidden();
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'posted']);
    }

    public function test_foreign_organization_financial_account_is_hidden_by_route_binding(): void
    {
        [$attacker] = $this->paymentFixture();
        [$victim, , , , $foreignAccount] = $this->paymentFixture();
        $this->actingAs($attacker)->patch(route('financial-accounts.update', $foreignAccount), [
            'name' => 'Stolen', 'code' => 'STOLEN', 'type' => 'cash', 'status' => 'active', 'currency_code' => 'MAD',
        ])->assertNotFound();
        $this->assertNotSame('Stolen', $foreignAccount->fresh()->name);
    }

    public function test_authoritative_payment_fields_are_derived_not_trusted(): void
    {
        [$owner, $organization, $store, $order, $account] = $this->paymentFixture();
        $payload = $this->requestPayload($account->id);
        $payload['payment_number'] = 'EVIL-1';
        $payload['status'] = 'reversed';
        $payload['payment_status'] = 'paid';
        $payload['received_by_user_id'] = 999999;
        $payload['reversed_by_user_id'] = 999999;
        $payload['reversed_at'] = now()->subYear()->toDateTimeString();
        $payload['payments'][0] = array_merge($payload['payments'][0], $payload);
        $this->actingAs($owner)->post(route('sales.orders.payments.store', $order), $payload)->assertRedirect();
        $this->assertDatabaseHas('payments', [
            'organization_id' => $organization->id, 'store_id' => $store->id, 'payment_number' => 'PAY-000001',
            'status' => 'posted', 'received_by_user_id' => $owner->id, 'reversed_by_user_id' => null, 'reversed_at' => null,
        ]);
        $this->assertSame('partially_paid', $order->fresh()->payment_status->value);
    }

    public function test_payment_status_payload_cannot_change_order_without_allocations(): void
    {
        [$owner, $organization, $store] = $this->paymentFixture();
        $draft = $this->createDraftOrder($owner, $organization, $store);
        $this->actingAs($owner)->patch(route('sales.orders.update', $draft), [
            'customer_id' => null, 'sale_date' => $draft->sale_date->toDateString(), 'currency_code' => 'MAD',
            'notes' => null, 'payment_status' => 'paid',
        ])->assertRedirect();
        $this->assertSame('unpaid', $draft->fresh()->payment_status->value);
    }

    public function test_payment_index_search_does_not_leak_foreign_payment_metadata(): void
    {
        [$owner, $organization, $store, $order, $account] = $this->paymentFixture();
        $ownPayment = $this->recordPayment($owner, $order, $account, '10.0000', ['reference' => 'OWN-REF']);
        [$victim, , , $victimOrder, $victimAccount] = $this->paymentFixture();
        $this->recordPayment($victim, $victimOrder, $victimAccount, '10.0000', ['reference' => 'SECRET-REF']);
        $this->activate($owner, $organization, $store);

        $this->actingAs($owner)->get(route('payments.index'))->assertInertia(fn (Assert $page) => $page
            ->has('payments.data', 1)
            ->where('payments.data.0.id', $ownPayment->id));
        $this->actingAs($owner)->get(route('payments.index', ['search' => 'SECRET-REF']))->assertInertia(fn (Assert $page) => $page
            ->has('payments.data', 0));
    }

    public function test_same_user_multi_organization_resources_require_explicit_context_switch(): void
    {
        [$user, $organizationA, $storeA] = $this->paymentFixture();
        $organizationB = $this->createOrganization($user, 'Organization B');
        $storeB = $this->createStore($organizationB, $user, 'Store B');
        $orderB = $this->createDraftOrder($user, $organizationB, $storeB);
        $this->addCustomLine($user, $orderB);
        $orderB = app(ConfirmSalesOrderAction::class)->execute($user, $orderB);
        $accountB = $this->createFinancialAccount($organizationB, ['code' => 'B-CASH']);
        $paymentB = $this->recordPayment($user, $orderB, $accountB, '10.0000');
        $this->activate($user, $organizationA, $storeA);

        $this->actingAs($user)->get(route('payments.show', $paymentB))->assertNotFound();
        $this->actingAs($user)->post(route('sales.orders.payments.store', $orderB), $this->requestPayload($accountB->id))->assertNotFound();
        $this->actingAs($user)->patch(route('financial-accounts.update', $accountB), [])->assertNotFound();
    }

    public function test_unrelated_user_cannot_resolve_payment_even_with_forged_active_ids(): void
    {
        [$owner, $organization, $store, $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '10.0000');
        $outsider = User::factory()->create();
        $this->activate($outsider, $organization, $store);
        $this->actingAs($outsider)->get(route('payments.show', $payment))->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function requestPayload(int $accountId): array
    {
        return [
            'client_operation_id' => (string) Str::uuid(),
            'payments' => [[
                'method' => 'cash', 'financial_account_id' => $accountId, 'amount' => '10.0000', 'payment_date' => now()->toDateString(),
            ]],
        ];
    }
}
