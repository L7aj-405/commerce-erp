<?php

namespace Tests\Feature\Payments;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Exchanges\CreateCustomerExchangeAction;
use App\Actions\Exchanges\FulfillCustomerExchangeReplacementAction;
use App\Actions\Exchanges\ReceiveCustomerExchangeReturnAction;
use App\Actions\Returns\CreateCustomerReturnAction;
use App\Actions\Returns\ReceiveCustomerReturnAction;
use App\Models\AuditLog;
use App\Models\CustomerExchange;
use App\Models\CustomerReturn;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PosTestCase;

/**
 * Phase 1 Step 6 (S-03): `payments.refund` and `payments.reverse` are independent. Role x action matrix over the
 * real HTTP boundaries: payment reversal, return refund and exchange refund.
 */
class PaymentPermissionMatrixTest extends PosTestCase
{
    /** @return array<string, array{bool, bool}> [holds payments.refund, holds payments.reverse] */
    public static function roles(): array
    {
        return [
            'refund only' => [true, false],
            'reverse only' => [false, true],
            'both' => [true, true],
            'neither' => [false, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_reversal_requires_reverse_and_never_refund(bool $refund, bool $reverse): void
    {
        [, $organization, $store] = $this->sale();
        $payment = Payment::query()->where('organization_id', $organization->id)->sole();
        $member = $this->member($organization, $store, ['payments.view'], $refund, $reverse);

        $response = $this->actingAs($member)->post(route('payments.reverse', $payment), ['reason' => 'Matrice']);

        if ($reverse) {
            $this->assertNotSame(403, $response->status());
            $this->assertSame('reversed', $payment->fresh()->status->value);
        } else {
            $response->assertForbidden();
            $this->assertSame('posted', $payment->fresh()->status->value);
        }
        $this->assertDatabaseCount('payment_refunds', 0);
    }

    #[DataProvider('roles')]
    public function test_return_refund_requires_refund_and_never_reverse(bool $refund, bool $reverse): void
    {
        [$owner, $organization, $store, , $order] = $this->sale();
        $this->issue($owner, $order);
        $return = $this->receivedReturn($owner, $order);
        $payment = Payment::query()->where('organization_id', $organization->id)->sole();
        $member = $this->member($organization, $store, ['sales_returns.view', 'payments.view'], $refund, $reverse);

        $response = $this->actingAs($member)->post(route('sales.returns.refunds.store', $return), [
            'client_operation_id' => (string) Str::uuid(), 'payment_id' => $payment->id, 'amount' => '400.0000', 'reason' => 'Matrice',
        ]);

        if ($refund) {
            $response->assertSessionHasNoErrors();
            $this->assertDatabaseCount('payment_refunds', 1);
            $this->assertSame($member->id, (int) PaymentRefund::query()->sole()->refunded_by_user_id);
            $this->assertTrue(AuditLog::query()->where('event', 'payment.refunded')->exists(), 'The refund is audited.');
        } else {
            $response->assertForbidden();
            $this->assertDatabaseCount('payment_refunds', 0);
            $this->assertFalse(AuditLog::query()->where('event', 'payment.refunded')->exists());
        }
    }

    #[DataProvider('roles')]
    public function test_exchange_refund_requires_refund_and_never_reverse(bool $refund, bool $reverse): void
    {
        [$owner, $organization, $store, $warehouse, $order] = $this->sale();
        $this->issue($owner, $order);
        $cheaper = $this->createProduct($organization, 'Cheaper', 'PM-CHEAP', ['default_sale_price' => '700.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $cheaper, '2.0000');
        $exchange = $this->awaitingRefundExchange($owner, $order, $cheaper->id);
        $payment = Payment::query()->where('organization_id', $organization->id)->sole();
        $member = $this->member($organization, $store, ['sales_exchanges.view', 'sales_exchanges.process', 'payments.view'], $refund, $reverse);

        $response = $this->actingAs($member)->post(route('sales.exchanges.refunds.store', $exchange), [
            'client_operation_id' => (string) Str::uuid(), 'payment_id' => $payment->id, 'amount' => '300.0000', 'reason' => 'Matrice',
        ]);

        if ($refund) {
            $response->assertSessionHasNoErrors();
            $this->assertSame('completed', $exchange->fresh()->status);
            $this->assertDatabaseCount('payment_refunds', 1);
        } else {
            $response->assertForbidden();
            $this->assertSame('awaiting_settlement', $exchange->fresh()->status);
            $this->assertDatabaseCount('payment_refunds', 0);
        }
    }

    public function test_the_exchange_page_offers_the_refund_form_only_with_the_refund_permission(): void
    {
        [$owner, $organization, $store, $warehouse, $order] = $this->sale();
        $this->issue($owner, $order);
        $cheaper = $this->createProduct($organization, 'Cheaper', 'PM-UI', ['default_sale_price' => '700.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $cheaper, '2.0000');
        $exchange = $this->awaitingRefundExchange($owner, $order, $cheaper->id);

        foreach ([[true, false, true], [false, true, false]] as [$refund, $reverse, $expected]) {
            $member = $this->member($organization, $store, ['sales_exchanges.view', 'sales_exchanges.process'], $refund, $reverse);
            $this->actingAs($member)->get(route('sales.exchanges.show', $exchange))->assertOk()
                ->assertInertia(fn ($page) => $page->where('can.refund', $expected));
        }
    }

    public function test_owner_holds_both_permissions_and_can_refund_and_reverse(): void
    {
        [$owner, $organization, , , $order] = $this->sale();
        $this->assertContains('payments.refund', $owner->permissionKeysFor($organization));
        $this->assertContains('payments.reverse', $owner->permissionKeysFor($organization));

        $this->issue($owner, $order);
        $return = $this->receivedReturn($owner, $order);
        $payment = Payment::query()->where('organization_id', $organization->id)->sole();
        $this->actingAs($owner)->post(route('sales.returns.refunds.store', $return), [
            'client_operation_id' => (string) Str::uuid(), 'payment_id' => $payment->id, 'amount' => '400.0000', 'reason' => 'Owner',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payment_refunds', 1);
    }

    public function test_another_organization_cannot_refund_or_reverse_even_with_both_permissions(): void
    {
        [$owner, $organization, , , $order] = $this->sale();
        $this->issue($owner, $order);
        $return = $this->receivedReturn($owner, $order);
        $payment = Payment::query()->where('organization_id', $organization->id)->sole();

        $attacker = User::factory()->create();
        $other = $this->createOrganization($attacker);
        $otherStore = $this->createStore($other, $attacker);
        $this->activate($attacker, $other, $otherStore);
        $this->assertContains('payments.refund', $attacker->permissionKeysFor($other));

        $this->actingAs($attacker)->post(route('payments.reverse', $payment), ['reason' => 'Attack'])->assertNotFound();
        $this->actingAs($attacker)->post(route('sales.returns.refunds.store', $return), [
            'client_operation_id' => (string) Str::uuid(), 'payment_id' => $payment->id, 'amount' => '400.0000', 'reason' => 'Attack',
        ])->assertStatus(404);
        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertSame('posted', $payment->fresh()->status->value);
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<string> $base */
    private function member($organization, $store, array $base, bool $refund, bool $reverse): User
    {
        $user = User::factory()->create();
        $permissions = array_merge($base, $refund ? ['payments.refund'] : [], $reverse ? ['payments.reverse'] : []);
        $this->addOrganizationMember($organization, $user, $permissions, roleName: 'Matrix '.Str::random(6));
        $this->addStoreMember($store, $user);
        $this->activate($user, $organization, $store);

        return $user;
    }

    private function receivedReturn(User $owner, SalesOrder $order): CustomerReturn
    {
        $return = app(CreateCustomerReturnAction::class)->execute($owner, $order->fresh(['lines']), [
            ['sales_order_line_id' => $order->lines()->firstOrFail()->id, 'quantity' => '1.0000'],
        ], 'Retour matrice', 'restock', (string) Str::uuid());

        return app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
    }

    private function awaitingRefundExchange(User $owner, SalesOrder $order, int $replacementVariantId): CustomerExchange
    {
        $exchange = app(CreateCustomerExchangeAction::class)->execute($owner, $order->fresh(), (string) Str::uuid(), [
            ['sales_order_line_id' => $order->lines()->whereNull('sales_order_addendum_id')->firstOrFail()->id, 'quantity' => '1.0000'],
        ], [['product_variant_id' => $replacementVariantId, 'quantity' => '1.0000']], 'Échange matrice', 'restock');
        app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchange);

        return app(FulfillCustomerExchangeReplacementAction::class)->execute($owner, $exchange->fresh())->fresh();
    }

    private function issue(User $owner, SalesOrder $order)
    {
        return app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
    }

    /** @return array{User,mixed,mixed,mixed,SalesOrder} one POS sale of 1 x 1000 paid in cash */
    private function sale(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $settings = $organization->settings ?? [];
        $settings['return_policy'] = ['enabled' => true, 'window_minutes' => 10080, 'allow_partial' => true, 'allow_full' => true, 'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock'];
        $organization->settings = $settings;
        $organization->save();
        $variant = $this->createProduct($organization, 'Product A', 'PM-A', ['default_sale_price' => '1000.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '3.0000');
        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($warehouse, [$this->posCatalogLine($variant)]))->assertRedirect();
        $order = SalesOrder::query()->where('organization_id', $organization->id)->sole()->fresh(['lines']);

        return [$owner, $organization, $store, $warehouse, $order];
    }
}
