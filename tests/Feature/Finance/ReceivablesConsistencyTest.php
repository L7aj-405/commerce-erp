<?php

namespace Tests\Feature\Finance;

use App\Actions\Payments\RefundPaymentAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\FinanceReceivablesService;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DocumentTestCase;

/**
 * Sprint 0 / WS4 — ORDER EXPOSURE (set-based read model) and the canonical
 * per-order rule (SalesOrderPaymentCalculator) never disagree; ISSUED-INVOICE
 * DEBT stays a distinct concept.
 */
class ReceivablesConsistencyTest extends DocumentTestCase
{
    private User $owner;

    private Organization $organization;

    private Store $store;

    private FinancialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withFreshAuthentication(level: 1);
        $this->travelTo(Carbon::parse('2026-11-15 10:00:00', 'UTC'));
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->organization->settings = [...($this->organization->settings ?? []), 'timezone' => 'Africa/Casablanca'];
        $this->organization->save();
        $this->store = $this->createStore($this->organization, $this->owner);
        $this->activate($this->owner, $this->organization, $this->store);
        $this->account = $this->createFinancialAccount($this->organization);
    }

    public function test_order_exposure_matches_the_canonical_per_order_rule_as_of_any_date(): void
    {
        $partial = $this->order('1000.0000');
        $this->pay($partial, '300.0000', '2026-09-10');

        $refunded = $this->order('500.0000');
        $refundedPayment = $this->pay($refunded, '500.0000', '2026-09-12');
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
        app(RefundPaymentAction::class)->execute($this->owner, $refundedPayment, $refunded, 'Annulation');
        $this->travelTo(Carbon::parse('2026-11-15 10:00:00', 'UTC'));

        $unpaid = $this->order('200.0000');
        $settled = $this->order('100.0000');
        $this->pay($settled, '100.0000', '2026-09-15');
        // 0.0040 MAD left: below one centime, settled at the currency precision.
        $residue = $this->order('50.0000');
        $this->pay($residue, Decimal::subtract((string) $residue->total_incl_tax, '0.0040'), '2026-09-15');

        // Received at 30 Sep 23:30 UTC = 1 Oct 00:30 in Casablanca.
        $returned = $this->order('400.0000');
        $this->pay($returned, '100.0000', '2026-09-20');
        $this->receivedReturn($returned, '150.0000', '2026-09-30 23:30:00');

        foreach (['2026-09-30', '2026-10-01', '2026-10-31'] as $date) {
            $asOf = Carbon::parse($date);
            $service = app(FinanceReceivablesService::class)->salesOrderReceivablesAsOf($this->organization->fresh(), $asOf, null, 50);
            $orders = SalesOrder::query()->where('organization_id', $this->organization->id)->get();
            $canonical = app(SalesOrderPaymentCalculator::class)->summaries($orders, $asOf);
            $expected = collect($canonical)->filter(fn (array $summary) => Decimal::compare($summary['remaining'], '0') > 0);

            $rows = collect($service['orders']->items())->keyBy('id');
            $this->assertEqualsCanonicalizing($expected->keys()->all(), $rows->keys()->all(), "Exposure orders as of {$date}");
            foreach ($expected as $orderId => $summary) {
                $this->assertSame($summary['remaining'], $rows[$orderId]['outstanding'], "Outstanding of order {$orderId} as of {$date}");
                $this->assertSame($summary['paid'], $rows[$orderId]['paid_amount']);
                $this->assertSame($summary['status'], $rows[$orderId]['payment_status']);
            }
            $total = $expected->reduce(fn (string $sum, array $summary) => Decimal::add($sum, $summary['remaining']), '0.0000');
            $this->assertSame(0, Decimal::compare($total, $service['total']), "Total as of {$date}");
            $this->assertSame($expected->where('status', 'unpaid')->count(), $service['status_counts']['unpaid']);
            $this->assertSame($expected->where('status', 'partially_paid')->count(), $service['status_counts']['partially_paid']);

            $summary = app(FinanceReceivablesService::class)->orderExposureSummaryAsOf($this->organization->fresh(), $asOf, null);
            $this->assertSame(0, Decimal::compare($summary['total'], $service['total']));
            $this->assertSame($service['status_counts'], $summary['status_counts']);
        }

        $sep = collect(app(FinanceReceivablesService::class)->salesOrderReceivablesAsOf($this->organization->fresh(), Carbon::parse('2026-09-30'), null)['orders']->items())->keyBy('id');
        $oct1 = collect(app(FinanceReceivablesService::class)->salesOrderReceivablesAsOf($this->organization->fresh(), Carbon::parse('2026-10-01'), null)['orders']->items())->keyBy('id');
        $this->assertArrayNotHasKey($settled->id, $sep->all());
        $this->assertArrayNotHasKey($residue->id, $sep->all(), 'A sub-centime residue is not a receivable.');
        $this->assertArrayHasKey($unpaid->id, $sep->all());
        $this->assertArrayNotHasKey($refunded->id, $sep->all(), 'Refund dated 5 Oct is not yet effective on 30 Sep.');
        $this->assertSame('300.0000', $sep[$returned->id]['outstanding'], 'The return received on 1 Oct (local) is not effective on 30 Sep.');
        $this->assertSame('150.0000', $oct1[$returned->id]['outstanding']);
    }

    public function test_exposure_is_paginated_in_the_database_and_scoped_to_the_tenant_and_store(): void
    {
        foreach (range(1, 3) as $i) {
            $this->order('100.0000');
        }
        $otherOwner = User::factory()->create();
        $other = $this->createOrganization($otherOwner, 'Other');
        $otherStore = $this->createStore($other, $otherOwner);
        $this->activate($otherOwner, $other, $otherStore);
        $foreign = $this->createDraftOrder($otherOwner, $other, $otherStore);
        $this->addCustomLine($otherOwner, $foreign, ['unit_price_excl_tax' => '999.0000']);
        app(ConfirmSalesOrderAction::class)->execute($otherOwner, $foreign);
        $this->activate($this->owner, $this->organization, $this->store);

        $page = app(FinanceReceivablesService::class)->salesOrderReceivablesAsOf($this->organization->fresh(), Carbon::parse('2026-11-15'), null, 2);
        $this->assertSame(3, $page['orders']->total());
        $this->assertCount(2, $page['orders']->items());
        $this->assertSame(3, $page['status_counts']['unpaid']);

        $secondStore = $this->createStore($this->organization, $this->owner, 'Second');
        $this->assertSame('0.0000', app(FinanceReceivablesService::class)->orderExposureSummaryAsOf($this->organization->fresh(), Carbon::parse('2026-11-15'), $secondStore)['total']);
    }

    public function test_expected_collection_picker_uses_the_canonical_exposure(): void
    {
        $order = $this->order('400.0000');
        $this->pay($order, '100.0000', '2026-11-01');
        $this->receivedReturn($order, '150.0000', '2026-11-02 10:00:00');

        $this->actingAs($this->owner)->get(route('finance.monthly-situation.index', ['month' => '2026-11']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('receivableOrders.0.id', $order->id)
                // The former inline query ignored returns and showed 300.0000.
                ->where('receivableOrders.0.outstanding', '150.0000'));
    }

    public function test_issued_invoice_debt_is_a_distinct_concept_from_order_exposure(): void
    {
        $uninvoiced = $this->order('200.0000');
        $invoiced = $this->order('300.0000');
        $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $invoiced));
        $asOf = Carbon::parse('2026-11-15');

        $exposure = app(FinanceReceivablesService::class)->orderExposureSummaryAsOf($this->organization->fresh(), $asOf, null)['total'];
        $invoiceDebt = app(FinanceReceivablesService::class)->totalAsOf($this->organization->fresh(), $asOf, null);

        $this->assertSame(0, Decimal::compare($exposure, Decimal::add((string) $uninvoiced->total_incl_tax, (string) $invoiced->total_incl_tax)));
        $this->assertSame(0, Decimal::compare($invoiceDebt, (string) $invoiced->total_incl_tax), 'An order without an issued invoice is exposure, not invoice debt.');
    }

    private function order(string $amount): SalesOrder
    {
        $order = $this->createDraftOrder($this->owner, $this->organization, $this->store, null, ['sale_date' => '2026-09-01']);
        $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => $amount]);

        return app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
    }

    private function pay(SalesOrder $order, string $amount, string $date)
    {
        return $this->recordPayment($this->owner, $order, $this->account, $amount, ['payment_date' => $date, 'client_operation_id' => (string) Str::uuid()]);
    }

    private function receivedReturn(SalesOrder $order, string $total, string $receivedAtUtc): void
    {
        DB::table('customer_returns')->insert([
            'organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'sales_order_id' => $order->id,
            'warehouse_id' => $this->createWarehouse($this->organization, 'Retours '.Str::random(4))->id,
            'return_number' => 'RET-'.Str::upper(Str::random(6)), 'client_operation_id' => (string) Str::uuid(),
            'status' => 'received', 'disposition' => 'restock', 'reason' => 'Test', 'currency_code' => 'MAD',
            'total_incl_tax' => $total, 'policy_snapshot' => '{}', 'received_at' => $receivedAtUtc,
            'created_at' => $receivedAtUtc, 'updated_at' => $receivedAtUtc,
        ]);
    }
}
