<?php

namespace Tests\Feature\Pos;

use App\Actions\Sales\CancelSalesOrderAction;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Pos\PosDraftLifecycle;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PaymentTestCase;

/**
 * Staging UAT (SO-000016): a confirmed, paid, cancelled and refunded POS order was offered
 * "Supprimer" with the help text "never confirmed, no commercial history". The UI flag and
 * the server must share ONE eligibility rule; a financially consequential order is never deletable.
 */
class CancelledPosOrderDeleteEligibilityTest extends PaymentTestCase
{
    /** confirmed -> paid -> cancelled -> refunded */
    public function test_confirmed_paid_cancelled_refunded_order_is_never_deletable_in_ui_or_server(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture('100.0000');
        $this->recordPayment($owner, $order, $account, '100.0000');
        app(CancelSalesOrderAction::class)->execute($owner, $order->fresh(), 'Client parti');
        DB::table('sales_orders')->where('id', $order->id)->update(['source' => 'pos']);
        $this->assertSame(1, DB::table('payment_refunds')->count());

        // Simulate the UAT record: the confirmation timestamp is not set on the row.
        DB::table('sales_orders')->where('id', $order->id)->update(['confirmed_at' => null]);

        $this->actingAs($owner)->get("/sales/orders/{$order->id}")->assertInertia(fn (Assert $page) => $page
            ->where('posDraft.can_delete', false));

        $this->actingAs($owner)->delete("/sales/orders/{$order->id}")->assertForbidden();
        $this->assertSame(1, SalesOrder::query()->whereKey($order->id)->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, DB::table('payment_allocations')->count());
        $this->assertSame(1, DB::table('payment_refunds')->count());
    }

    public function test_lifecycle_itself_refuses_a_paid_refunded_order_even_if_authorization_is_bypassed(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture('100.0000');
        $this->recordPayment($owner, $order, $account, '100.0000');
        app(CancelSalesOrderAction::class)->execute($owner, $order->fresh(), 'Client parti');
        DB::table('sales_orders')->where('id', $order->id)->update(['source' => 'pos', 'confirmed_at' => null]);

        $result = app(PosDraftLifecycle::class)->discard($owner, $order->fresh(), PosDraftLifecycle::REASON_CANCELLED_DELETE);

        $this->assertSame(PosDraftLifecycle::OUTCOME_BLOCKED, $result['outcome']);
        $this->assertContains('confirmed', $result['reasons']);
        $this->assertContains('payments', $result['reasons']);
        $this->assertContains('refunds', $result['reasons']);
        $this->assertNotNull(SalesOrder::query()->find($order->id));
    }

    public function test_confirmed_cancelled_order_without_payment_is_not_deletable_even_if_timestamp_is_missing(): void
    {
        [$owner, , , $order] = $this->paymentFixture('100.0000');
        app(CancelSalesOrderAction::class)->execute($owner, $order->fresh(), 'Annulée');
        DB::table('sales_orders')->where('id', $order->id)->update(['source' => 'pos', 'confirmed_at' => null]);

        $this->actingAs($owner)->get("/sales/orders/{$order->id}")->assertInertia(fn (Assert $page) => $page
            ->where('posDraft.can_delete', false));
        $this->actingAs($owner)->delete("/sales/orders/{$order->id}")->assertForbidden();
        $this->assertNotNull(SalesOrder::query()->find($order->id));
    }

    public function test_genuinely_disposable_cancelled_pos_draft_is_still_deletable(): void
    {
        [$owner, , , , , $draft] = $this->disposableDraft();

        $this->actingAs($owner)->post("/sales/orders/{$draft->id}/cancel", ['reason' => 'Erreur'])->assertSessionHasNoErrors();

        $this->actingAs($owner)->get("/sales/orders/{$draft->id}")->assertInertia(fn (Assert $page) => $page
            ->where('posDraft.can_delete', true));
        $this->actingAs($owner)->delete("/sales/orders/{$draft->id}")->assertRedirect(route('sales.orders.index'));
        $this->assertNull(SalesOrder::query()->find($draft->id));
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales_order.pos_draft_discarded', 'auditable_id' => $draft->id]);
    }

    /** @return array{0: User, 1: mixed, 2: mixed, 3: mixed, 4: mixed, 5: SalesOrder} */
    private function disposableDraft(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $draft = $this->createDraftOrder($owner, $organization, $store);
        DB::table('sales_orders')->where('id', $draft->id)->update(['source' => 'pos']);

        return [$owner, $organization, $store, null, null, $draft->fresh()];
    }
}
