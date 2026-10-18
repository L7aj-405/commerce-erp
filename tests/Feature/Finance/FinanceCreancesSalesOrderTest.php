<?php

namespace Tests\Feature\Finance;

use App\Actions\Payments\RefundPaymentAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\CustomerReturn;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\FinanceReceivablesService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DocumentTestCase;

class FinanceCreancesSalesOrderTest extends DocumentTestCase
{
    public function test_page_lists_unpaid_and_partial_orders_but_excludes_fully_paid_orders(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $account = $this->createFinancialAccount($organization);

        $unpaid = $this->confirmedOrder($owner, $organization, $store, 'Impayée');
        $partial = $this->confirmedOrder($owner, $organization, $store, 'Partielle');
        $this->recordPayment($owner, $partial, $account, '4000.0000', ['payment_date' => '2026-10-15']);
        $paid = $this->confirmedOrder($owner, $organization, $store, 'Payée');
        $this->recordPayment($owner, $paid, $account, '10000.0000', ['payment_date' => '2026-10-16']);

        $this->activate($owner, $organization, $store);

        $this->actingAs($owner)
            ->get(route('finance.creances', ['period' => '2026-10', 'store_id' => $store->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Finance/Creances')
                ->where('total', '16000.0000')
                ->has('orders.data', 2)
                ->where('orders.data.0.id', $unpaid->id)
                ->where('orders.data.0.total_incl_tax', '10000.0000')
                ->where('orders.data.0.paid_amount', '0.0000')
                ->where('orders.data.0.outstanding', '10000.0000')
                ->where('orders.data.0.payment_status', 'unpaid')
                ->where('orders.data.1.id', $partial->id)
                ->where('orders.data.1.paid_amount', '4000.0000')
                ->where('orders.data.1.outstanding', '6000.0000')
                ->where('orders.data.1.payment_status', 'partially_paid')
                ->where('orders.data.1.last_payment_date', '2026-10-15')
                ->missing('invoices'));

        $this->assertNotContains($paid->id, collect(
            app(FinanceReceivablesService::class)
                ->salesOrderReceivablesAsOf($organization, Carbon::parse('2026-10-31'), $store)['orders']
                ->items(),
        )->pluck('id')->all());
    }

    public function test_returns_refunds_and_tenant_isolation_follow_the_authoritative_order_calculator(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $account = $this->createFinancialAccount($organization);

        $partialReturn = $this->confirmedOrder($owner, $organization, $store, 'Paiement et retour');
        $this->recordPayment($owner, $partialReturn, $account, '4000.0000', ['payment_date' => '2026-10-12']);
        $this->receivedReturn($owner, $partialReturn, $warehouse->id, '2000.0000');

        $refunded = $this->confirmedOrder($owner, $organization, $store, 'Remboursée');
        $payment = $this->recordPayment($owner, $refunded, $account, '4000.0000', ['payment_date' => '2026-10-13']);
        app(RefundPaymentAction::class)->execute($owner, $payment, $refunded, 'Remboursement de contrôle');

        $foreignOwner = User::factory()->create();
        $foreignOrganization = $this->createOrganization($foreignOwner, 'Autre organisation');
        $foreignStore = $this->createStore($foreignOrganization, $foreignOwner);
        $foreignOrder = $this->confirmedOrder($foreignOwner, $foreignOrganization, $foreignStore, 'Étrangère');

        $result = app(FinanceReceivablesService::class)
            ->salesOrderReceivablesAsOf($organization, Carbon::parse('2026-10-31'), null);
        $rows = collect($result['orders']->items())->keyBy('id');

        $this->assertSame('14000.0000', $result['total']);
        $this->assertSame('8000.0000', $rows[$partialReturn->id]['total_incl_tax']);
        $this->assertSame('4000.0000', $rows[$partialReturn->id]['paid_amount']);
        $this->assertSame('4000.0000', $rows[$partialReturn->id]['outstanding']);
        $this->assertSame('partially_paid', $rows[$partialReturn->id]['payment_status']);
        $this->assertSame('10000.0000', $rows[$refunded->id]['total_incl_tax']);
        $this->assertSame('0.0000', $rows[$refunded->id]['paid_amount']);
        $this->assertSame('10000.0000', $rows[$refunded->id]['outstanding']);
        $this->assertSame('unpaid', $rows[$refunded->id]['payment_status']);
        $this->assertArrayNotHasKey($foreignOrder->id, $rows->all());
    }

    private function confirmedOrder(User $owner, Organization $organization, Store $store, string $description): SalesOrder
    {
        $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-10-10']);
        $this->addCustomLine($owner, $order, ['description' => $description, 'unit_price_excl_tax' => '10000.0000']);

        return app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
    }

    private function receivedReturn(User $owner, SalesOrder $order, int $warehouseId, string $amount): void
    {
        $return = new CustomerReturn;
        $return->organization_id = $order->organization_id;
        $return->store_id = $order->store_id;
        $return->sales_order_id = $order->id;
        $return->warehouse_id = $warehouseId;
        $return->return_number = 'RET-'.$order->id;
        $return->client_operation_id = (string) Str::uuid();
        $return->status = 'received';
        $return->disposition = 'no_restock';
        $return->reason = 'Fixture de créance';
        $return->currency_code = $order->currency_code;
        $return->subtotal_excl_tax = $amount;
        $return->discount_total = '0.0000';
        $return->tax_total = '0.0000';
        $return->total_incl_tax = $amount;
        $return->policy_snapshot = [];
        $return->created_by_user_id = $owner->id;
        $return->received_at = '2026-10-14 12:00:00';
        $return->received_by_user_id = $owner->id;
        $return->save();
    }
}
