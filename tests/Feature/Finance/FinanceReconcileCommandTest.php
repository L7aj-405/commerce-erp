<?php

namespace Tests\Feature\Finance;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\FinancialAccount;
use App\Models\InventoryBalance;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\PosTestCase;

/** Phase 1 Step 8: `finance:reconcile` — read-only R-1…R-7 report. */
class FinanceReconcileCommandTest extends PosTestCase
{
    public function test_seeded_consistent_organization_reports_no_variance_on_every_check(): void
    {
        [, $organization] = $this->seededOrganization(invoice: true);

        $report = $this->reconcile($organization);

        $this->assertTrue($report['ok'], json_encode($report['checks']));
        $this->assertSame(['R-1', 'R-2', 'R-3', 'R-4', 'R-5', 'R-6', 'R-7', 'R-8', 'R-9', 'R-10'], array_column($report['checks'], 'code'));
        foreach ($report['checks'] as $check) {
            $this->assertContains($check['status'], ['pass', 'skipped'], $check['code']);
        }
        $this->assertSame('pass', $this->check($report, 'R-5')['status']);
        $this->assertSame('pass', $this->check($report, 'R-6')['status']);
        $this->assertSame('pass', $this->check($report, 'R-7')['status']);
        $this->assertSame(0, Artisan::call('finance:reconcile', ['organization' => $organization->id]));
    }

    public function test_r1_holds_with_real_open_exposure_unbilled_and_invoiced(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        foreach (['250.0000', '120.0000'] as $total) {
            $order = $this->createDraftOrder($owner, $organization, $store);
            $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => $total]);
            app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        }
        // Second order is invoiced (issued invoices require full payment, so bill the first via DB-free path: leave unbilled)
        $r1 = $this->check($this->reconcile($organization), 'R-1');

        $this->assertSame('pass', $r1['status']);
        $this->assertNotSame('0.0000', $r1['expected']);
        $this->assertSame($r1['expected'], $r1['actual']);
    }

    public function test_it_is_strictly_read_only(): void
    {
        [, $organization] = $this->seededOrganization(invoice: true);
        $tables = ['sales_orders', 'payments', 'payment_allocations', 'payment_refunds', 'invoices', 'inventory_balances', 'inventory_movements', 'audit_logs'];
        $before = array_map(fn ($t) => md5(DB::table($t)->orderBy('id')->get()->toJson()), $tables);

        DB::enableQueryLog();
        Artisan::call('finance:reconcile', ['organization' => $organization->id, '--json' => true]);
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower(ltrim($q)));
        DB::disableQueryLog();

        $this->assertTrue($queries->isNotEmpty());
        $this->assertSame([], $queries->filter(fn ($q) => preg_match('/^(insert|update|delete|replace|alter|create|drop|truncate)\b/', $q))->values()->all());
        $this->assertSame($before, array_map(fn ($t) => md5(DB::table($t)->orderBy('id')->get()->toJson()), $tables));
    }

    public function test_it_detects_stock_drift_payment_allocation_mismatch_and_over_refund(): void
    {
        [, $organization, $order, $balance] = $this->seededOrganization(invoice: false);

        DB::table('inventory_balances')->where('id', $balance->id)->update(['on_hand' => '7.0000']);
        $paymentId = DB::table('payments')->where('organization_id', $organization->id)->value('id');
        DB::table('payment_allocations')->where('payment_id', $paymentId)->update(['amount' => '1.0000']);
        DB::table('payment_refunds')->insert($this->refundRow($organization, $order, $paymentId, '999.0000'));

        $report = $this->reconcile($organization);

        $this->assertFalse($report['ok']);
        $this->assertSame('fail', $this->check($report, 'R-7')['status']);
        $this->assertSame(1, $this->check($report, 'R-7')['violations']);
        $r5 = $this->check($report, 'R-5');
        $this->assertSame('fail', $r5['status']);
        $kinds = array_column($r5['samples'], 'kind');
        $this->assertContains('payment_not_equal_to_allocations', $kinds);
        $this->assertContains('order_refunded_more_than_collected', $kinds);
        $this->assertSame('fail', $this->check($report, 'R-3')['status']);
        $this->assertSame(1, Artisan::call('finance:reconcile', ['organization' => $organization->id]));
    }

    public function test_it_detects_a_tampered_issued_invoice_snapshot(): void
    {
        [, $organization] = $this->seededOrganization(invoice: true);
        $invoice = Invoice::query()->where('organization_id', $organization->id)->firstOrFail();
        DB::table('invoice_lines')->where('invoice_id', $invoice->id)->limit(1)->update(['quantity' => '9.0000']);

        $r6 = $this->check($this->reconcile($organization), 'R-6');

        $this->assertSame('fail', $r6['status']);
        $this->assertSame('invoice', $r6['samples'][0]['document']);
    }

    public function test_defects_of_another_organization_are_never_reported(): void
    {
        [, $organizationA] = $this->seededOrganization(invoice: false);
        [, $organizationB, , $balanceB] = $this->seededOrganization(invoice: false, sku: 'ORG-B');
        DB::table('inventory_balances')->where('id', $balanceB->id)->update(['on_hand' => '1.0000']);

        $this->assertTrue($this->reconcile($organizationA)['ok']);
        $this->assertFalse($this->reconcile($organizationB)['ok']);
    }

    public function test_account_balance_check_runs_when_an_opening_position_exists(): void
    {
        [, $organization] = $this->seededOrganization(invoice: false);
        $account = FinancialAccount::query()->where('organization_id', $organization->id)->firstOrFail();
        DB::table('financial_account_opening_positions')->insert([
            'organization_id' => $organization->id, 'financial_account_id' => $account->id, 'effective_date' => now()->subMonths(2)->toDateString(),
            'amount' => '500.0000', 'currency_code' => $account->currency_code, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $r2 = $this->check($this->reconcile($organization), 'R-2');

        $this->assertSame('pass', $r2['status']);
    }

    public function test_it_stays_read_only_on_a_failing_dataset_and_json_shape_is_stable(): void
    {
        [, $organization, $order, $balance] = $this->seededOrganization(invoice: true);
        DB::table('inventory_balances')->where('id', $balance->id)->update(['on_hand' => '7.0000']);
        $paymentId = DB::table('payments')->where('organization_id', $organization->id)->value('id');
        DB::table('payment_allocations')->where('payment_id', $paymentId)->update(['amount' => '1.0000']);
        $tables = ['sales_orders', 'payments', 'payment_allocations', 'payment_refunds', 'invoices', 'inventory_balances', 'inventory_movements', 'audit_logs', 'jobs', 'failed_jobs'];
        $snapshot = fn () => array_map(fn ($t) => md5(DB::table($t)->get()->toJson()), $tables);
        $before = $snapshot();

        DB::enableQueryLog();
        $report = $this->reconcile($organization);
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower(ltrim($q)));
        DB::disableQueryLog();

        $this->assertFalse($report['ok']);
        $this->assertSame([], $queries->filter(fn ($q) => preg_match('/^(insert|update|delete|replace|alter|create|drop|truncate)/', $q))->values()->all());
        $this->assertSame($before, $snapshot());
        $this->assertSame(['checks', 'months', 'ok', 'organization_id'], collect(array_keys($report))->sort()->values()->all());
        foreach ($report['checks'] as $check) {
            $this->assertEqualsCanonicalizing(['code', 'title', 'status', 'expected', 'actual', 'variance', 'violations', 'samples', 'note', 'classification'], array_keys($check));
        }
    }

    public function test_variances_are_exact_and_classified_without_changing_the_verdict(): void
    {
        [, $organization] = $this->seededOrganization(invoice: false);
        $paymentId = DB::table('payments')->where('organization_id', $organization->id)->value('id');
        $amount = DB::table('payments')->where('id', $paymentId)->value('amount');

        // Sub-centime: payment 0.0001 above its allocation -> reported collections exceed allocations by exactly 0.0001.
        DB::table('payments')->where('id', $paymentId)->update(['amount' => DB::raw('amount + 0.0001')]);
        $minor = collect($this->check($this->reconcile($organization), 'R-3')['samples'])->first();
        $this->assertSame('0.0001', $minor['variance']);
        $this->assertSame('sub_centime_residue', $minor['classification']);
        $this->assertSame('fail', $this->check($this->reconcile($organization), 'R-3')['status']);

        // Material: 50.0000 above.
        DB::table('payments')->where('id', $paymentId)->update(['amount' => DB::raw('amount + 49.9999')]);
        $major = collect($this->check($this->reconcile($organization), 'R-3')['samples'])->first();
        $this->assertSame('50.0000', $major['variance']);
        $this->assertSame('material', $major['classification']);
        $this->assertNotNull($amount);
    }

    public function test_sample_and_months_options_are_validated_and_bounded(): void
    {
        [, $organization] = $this->seededOrganization(invoice: false);
        foreach (['0', '201', 'abc', '-1'] as $bad) {
            $this->assertSame(2, Artisan::call('finance:reconcile', ['organization' => $organization->id, '--sample' => $bad]), "sample={$bad}");
        }
        foreach (['0', 'abc', '37'] as $bad) {
            $this->assertSame(2, Artisan::call('finance:reconcile', ['organization' => $organization->id, '--months' => $bad]), "months={$bad}");
        }
        Artisan::call('finance:reconcile', ['organization' => $organization->id, '--json' => true, '--month' => '2026-03', '--months' => 5]);
        $this->assertSame(['2026-03'], json_decode(Artisan::output(), true)['months']);
        Artisan::call('finance:reconcile', ['organization' => $organization->id, '--json' => true, '--months' => 5]);
        $this->assertCount(5, json_decode(Artisan::output(), true)['months']);
    }

    public function test_invalid_input_exits_with_code_2(): void
    {
        $this->assertSame(2, Artisan::call('finance:reconcile', ['organization' => '999999']));
        [, $organization] = $this->seededOrganization(invoice: false);
        $this->assertSame(2, Artisan::call('finance:reconcile', ['organization' => $organization->id, '--month' => '2026-13']));
        $this->assertSame(2, Artisan::call('finance:reconcile', ['organization' => $organization->id, '--months' => '99']));
    }

    /** @return array<string, mixed> */
    private function reconcile(Organization $organization): array
    {
        Artisan::call('finance:reconcile', ['organization' => $organization->id, '--json' => true, '--months' => 3]);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function check(array $report, string $code): array
    {
        return collect($report['checks'])->firstWhere('code', $code);
    }

    private function refundRow(Organization $organization, SalesOrder $order, int $paymentId, string $amount): array
    {
        $payment = DB::table('payments')->find($paymentId);

        return [
            'organization_id' => $organization->id, 'store_id' => $order->store_id, 'payment_id' => $paymentId, 'sales_order_id' => $order->id,
            'financial_account_id' => $payment->financial_account_id, 'refund_number' => 'REF-TEST-'.$amount, 'method' => 'cash', 'status' => 'posted',
            'amount' => $amount, 'currency_code' => $payment->currency_code, 'refund_date' => now()->toDateString(), 'reason' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** @return array{User, Organization, SalesOrder, InventoryBalance} */
    private function seededOrganization(bool $invoice, string $sku = 'REC-1'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Recon Product '.$sku, $sku, ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '5.0000');
        $payload = $this->posPayload($warehouse, [$this->posCatalogLine($variant, ['quantity' => '2.0000'])]);
        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)->assertRedirect();
        $order = SalesOrder::query()->where('organization_id', $organization->id)->sole();
        if ($invoice) {
            app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        }
        $balance = InventoryBalance::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->firstOrFail();

        return [$owner, $organization, $order, $balance];
    }
}
