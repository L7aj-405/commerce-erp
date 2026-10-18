<?php

namespace Tests\Feature\Finance;

use App\Actions\Payments\RefundPaymentAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\CustomerReturn;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Finance\FinanceMonthlyReportService;
use App\Services\Finance\FinancePeriod;
use Illuminate\Support\Str;
use Tests\Support\DocumentTestCase;

class FinanceVentesPaymentVisibilityTest extends DocumentTestCase
{
    public function test_sales_totals_and_authoritative_payment_visibility_cover_unpaid_partial_paid_returns_and_refunds(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $account = $this->createFinancialAccount($organization);

        $unpaid = $this->confirmedOrder($owner, $organization, $store, 'UNPAID');

        $partial = $this->confirmedOrder($owner, $organization, $store, 'PARTIAL');
        $this->recordPayment($owner, $partial, $account, '4000.0000');

        $paid = $this->confirmedOrder($owner, $organization, $store, 'PAID');
        $this->recordPayment($owner, $paid, $account, '10000.0000');

        $returned = $this->confirmedOrder($owner, $organization, $store, 'RETURNED');
        $this->receivedReturn($owner, $returned, $warehouse->id, '2000.0000');

        $partialReturn = $this->confirmedOrder($owner, $organization, $store, 'PARTIAL-RETURN');
        $this->recordPayment($owner, $partialReturn, $account, '4000.0000');
        $this->receivedReturn($owner, $partialReturn, $warehouse->id, '2000.0000');

        $refunded = $this->confirmedOrder($owner, $organization, $store, 'REFUNDED');
        $payment = $this->recordPayment($owner, $refunded, $account, '4000.0000');
        app(RefundPaymentAction::class)->execute($owner, $payment, $refunded, 'Annulation du paiement');

        $rows = collect(app(FinanceMonthlyReportService::class)
            ->ventesBreakdown($organization, FinancePeriod::fromMonth('2026-10'), $store, 30)
            ->items())
            ->keyBy('id');

        $this->assertRow($rows[$unpaid->id], '10000.0000', '0.0000', '10000.0000', '0.0000', '10000.0000', 'unpaid');
        $this->assertRow($rows[$partial->id], '10000.0000', '0.0000', '10000.0000', '4000.0000', '6000.0000', 'partially_paid');
        $this->assertRow($rows[$paid->id], '10000.0000', '0.0000', '10000.0000', '10000.0000', '0.0000', 'paid');
        $this->assertRow($rows[$returned->id], '10000.0000', '2000.0000', '8000.0000', '0.0000', '8000.0000', 'unpaid');
        $this->assertRow($rows[$partialReturn->id], '10000.0000', '2000.0000', '8000.0000', '4000.0000', '4000.0000', 'partially_paid');
        $this->assertRow($rows[$refunded->id], '10000.0000', '0.0000', '10000.0000', '0.0000', '10000.0000', 'unpaid');
    }

    private function confirmedOrder(User $owner, $organization, $store, string $number): SalesOrder
    {
        $order = $this->createDraftOrder($owner, $organization, $store, null, [
            'order_number' => $number,
            'sale_date' => '2026-10-10',
        ]);
        $this->addCustomLine($owner, $order, ['description' => $number, 'unit_price_excl_tax' => '10000.0000']);

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
        $return->reason = 'Fixture de reporting Finance';
        $return->currency_code = $order->currency_code;
        $return->subtotal_excl_tax = $amount;
        $return->discount_total = '0.0000';
        $return->tax_total = '0.0000';
        $return->total_incl_tax = $amount;
        $return->policy_snapshot = [];
        $return->created_by_user_id = $owner->id;
        $return->received_at = now();
        $return->received_by_user_id = $owner->id;
        $return->save();
    }

    private function assertRow(array $row, string $gross, string $returns, string $net, string $paid, string $remaining, string $status): void
    {
        $this->assertSame($gross, $row['total_incl_tax']);
        $this->assertSame($returns, $row['returned_total']);
        $this->assertSame($net, $row['net_total']);
        $this->assertSame($paid, $row['paid_amount']);
        $this->assertSame($remaining, $row['remaining_amount']);
        $this->assertSame($status, $row['payment_status']);
    }
}
