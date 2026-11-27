<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceObligationAction;
use App\Actions\Finance\ManageFinancePaymentInstrumentAction;
use App\Actions\Finance\RecordFinanceOutgoingPaymentAction;
use App\Actions\Finance\ReverseFinanceOutgoingPaymentAction;
use App\Actions\Finance\SetFinancialAccountOpeningPositionAction;
use App\Actions\Payments\RefundPaymentAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\FinanceOutgoingPayment;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\FinancialAccountBalanceService;
use App\Services\Finance\FinancialAccountMovementJournal;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\PaymentTestCase;

/** FIN-AUDIT-002: read-only per-account movement journal (projection of the existing financial records). */
class FinancialAccountMovementJournalTest extends PaymentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private SalesOrder $order;

    private FinancialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->org, $this->store, $this->order, $this->account] = $this->paymentFixture('5000.0000');
        $this->account->accepted_methods = ['cash', 'bank_transfer', 'card', 'cheque'];
        $this->account->type = 'bank';
        $this->account->save();
        $this->activate($this->owner, $this->org, $this->store);
        $this->setOpening('1000.0000', now()->subDays(20)->toDateString());
    }

    // ------------------------------------------------------------------ helpers

    private function setOpening(string $amount, string $date, ?FinancialAccount $account = null): void
    {
        app(SetFinancialAccountOpeningPositionAction::class)->execute($this->owner, $account ?? $this->account, ['effective_date' => $date, 'amount' => $amount, 'currency_code' => 'MAD', 'reference' => 'OPEN']);
    }

    private function payIn(string $amount, ?string $date = null, array $overrides = []): Payment
    {
        return $this->recordPayment($this->owner, $this->order, $this->account, $amount, array_replace(['payment_date' => $date ?? now()->subDays(5)->toDateString()], $overrides));
    }

    private function payOut(string $amount, ?string $date = null, ?int $storeId = null): FinanceOutgoingPayment
    {
        $date ??= now()->subDays(4)->toDateString();
        $obligation = app(ManageFinanceObligationAction::class)->create($this->owner, $this->org, [
            'store_id' => $storeId, 'category' => 'rent', 'label' => 'Loyer', 'supplier_id' => null, 'beneficiary_name' => 'Bailleur', 'reference' => null,
            'due_date' => $date, 'planned_amount' => $amount, 'currency_code' => 'MAD', 'planned_payment_method' => null,
            'preferred_financial_account_id' => null, 'notes' => null,
        ]);

        return app(RecordFinanceOutgoingPaymentAction::class)->execute($this->owner, $this->org, [
            'store_id' => $storeId, 'financial_account_id' => $this->account->id, 'supplier_id' => null, 'beneficiary_name' => 'Bailleur',
            'payment_method' => 'bank_transfer', 'amount' => $amount, 'currency_code' => 'MAD', 'payment_date' => $date,
            'reference' => 'VIR-1', 'notes' => null, 'idempotency_key' => (string) Str::uuid(),
            'allocations' => [['obligation_id' => $obligation->id, 'amount' => $amount]],
        ]);
    }

    private function svc(): FinancialAccountMovementJournal
    {
        return app(FinancialAccountMovementJournal::class);
    }

    /** @return array{0:array<string,mixed>,1:bool} */
    private function journalCtx(?FinancialAccount $account = null): array
    {
        $account ??= $this->account;
        $ctx = $this->svc()->context($this->org, $account, now());

        return [$ctx, $ctx['configured'] && $this->svc()->reconcile($this->org, $account, $ctx)['reconciled']];
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    private function journalRows(array $filters = [], ?FinancialAccount $account = null, int $page = 1, int $per = 200): array
    {
        $account ??= $this->account;
        [$ctx, $running] = $this->journalCtx($account);

        return $this->svc()->page($this->org, $account, $ctx, $filters, $this->owner, $page, $per, $running, '/x')->items();
    }

    private function acctBalance(?FinancialAccount $account = null): ?string
    {
        $account ??= $this->account;

        return app(FinancialAccountBalanceService::class)->organizationPosition($this->org, now(), $account->id)['accounts'][0]['balance'];
    }

    private function findRow(array $rows, string $type, ?string $ref = null): array
    {
        return collect($rows)->first(fn ($r) => $r['type'] === $type && ($ref === null || $r['reference'] === $ref));
    }

    /** @return array<string,mixed> */
    private function financialSnapshot(): array
    {
        return collect(['payments', 'payment_allocations', 'payment_refunds', 'finance_outgoing_payments', 'finance_outgoing_payment_allocations', 'financial_account_opening_positions', 'finance_payment_instruments', 'financial_accounts'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toArray()])->all();
    }

    // -------------------------------------------------------------------- tests

    public function test_opening_position_customer_payment_and_supplier_payment_rows(): void
    {
        $payment = $this->payIn('300.0000');
        $out = $this->payOut('50.0000');
        $rows = $this->journalRows();

        $this->assertSame(['opening_balance', 'customer_payment', 'supplier_payment'], array_column($rows, 'type'));
        $this->assertSame('1000.0000', $rows[0]['money_in']);
        $this->assertSame('1000.0000', $rows[0]['running_balance']);
        $this->assertSame($payment->payment_number, $rows[1]['reference']);
        $this->assertSame('300.0000', $rows[1]['money_in']);
        $this->assertNull($rows[1]['money_out']);
        $this->assertSame($this->owner->name, $rows[1]['user']);
        $this->assertSame('1300.0000', $rows[1]['running_balance']);
        $this->assertSame($out->payment_number, $rows[2]['reference']);
        $this->assertSame('50.0000', $rows[2]['money_out']);
        $this->assertSame('1250.0000', $rows[2]['running_balance']);
        $this->assertStringContainsString('Loyer', $rows[2]['documents'][0]);
        $this->assertSame('1250.0000', $this->acctBalance());
    }

    public function test_supplier_payment_reversal_is_two_linked_rows_that_net_to_zero(): void
    {
        $out = $this->payOut('50.0000');
        app(ReverseFinanceOutgoingPaymentAction::class)->execute($this->owner, $out, 'Erreur de saisie');
        $rows = $this->journalRows();

        $original = $this->findRow($rows, 'supplier_payment');
        $reversal = $this->findRow($rows, 'supplier_payment_reversal');
        $this->assertSame('reversed', $original['status']);
        $this->assertSame('50.0000', $original['money_out']);
        $this->assertSame('50.0000', $reversal['money_in']);
        $this->assertSame('Erreur de saisie', $reversal['reason']);
        $this->assertSame($this->owner->name, $reversal['user']);
        $this->assertSame('supplier_payment_reversal', $original['linked']['type']);
        $this->assertSame('supplier_payment', $reversal['linked']['type']);
        $this->assertSame('1000.0000', end($rows)['running_balance']);
        $this->assertSame('1000.0000', $this->acctBalance());
        $this->assertTrue($this->journalCtx()[1]);
    }

    public function test_customer_payment_reversal_and_customer_refund(): void
    {
        $reversed = $this->payIn('200.0000');
        app(ReversePaymentAction::class)->execute($this->owner, $reversed, 'Doublon');
        $refunded = $this->payIn('120.0000');
        app(RefundPaymentAction::class)->execute($this->owner, $refunded, $this->order->fresh(), 'Annulation commande');
        $rows = $this->journalRows();

        $this->assertSame('out', $this->findRow($rows, 'customer_payment_reversal')['direction']);
        $this->assertSame('Doublon', $this->findRow($rows, 'customer_payment_reversal')['reason']);
        $refund = $this->findRow($rows, 'customer_refund');
        $this->assertSame('120.0000', $refund['money_out']);
        $this->assertSame('Annulation commande', $refund['reason']);
        // 1000 + 200 - 200 (reversed pair) + 120 - 120 (refund)
        $this->assertSame('1000.0000', $this->acctBalance());
        $this->assertSame('1000.0000', end($rows)['running_balance']);
        $this->assertTrue($this->journalCtx()[1]);
    }

    public function test_updated_opening_position_shows_current_value_and_original_is_only_in_audit(): void
    {
        $this->payIn('100.0000');
        $this->setOpening('2000.0000', now()->subDays(20)->toDateString());
        $rows = $this->journalRows();

        $this->assertSame(1, collect($rows)->where('type', 'opening_balance')->count());
        $this->assertSame('2000.0000', $rows[0]['money_in']);
        $this->assertSame('2100.0000', end($rows)['running_balance']);
        $this->assertSame('2100.0000', $this->acctBalance());
        $this->assertDatabaseHas('audit_logs', ['event' => 'financial_account.opening_position_updated']);
    }

    public function test_pre_opening_movements_are_flagged_and_excluded_from_the_balance(): void
    {
        $this->payIn('70.0000', now()->subDays(30)->toDateString());
        $this->payIn('30.0000');
        $rows = $this->journalRows();

        $pre = collect($rows)->firstWhere('balance_scope', 'pre_opening');
        $this->assertSame('70.0000', $pre['money_in']);
        $this->assertNull($pre['running_balance']);
        $this->assertSame('customer_payment', $rows[0]['type']);       // chronologically before the opening row
        $this->assertSame('opening_balance', $rows[1]['type']);
        $this->assertSame('1030.0000', $this->acctBalance());
        $this->assertSame('1030.0000', end($rows)['running_balance']);
        $this->assertTrue($this->journalCtx()[1]);
    }

    public function test_cleared_cheque_posts_one_movement_and_return_shows_the_reversal_with_the_instrument(): void
    {
        $obligation = app(ManageFinanceObligationAction::class)->create($this->owner, $this->org, [
            'store_id' => null, 'category' => 'rent', 'label' => 'Loyer', 'supplier_id' => null, 'beneficiary_name' => 'Bailleur', 'reference' => null,
            'due_date' => now()->subDays(3)->toDateString(), 'planned_amount' => '400.0000', 'currency_code' => 'MAD', 'planned_payment_method' => null,
            'preferred_financial_account_id' => null, 'notes' => null,
        ]);
        $action = app(ManageFinancePaymentInstrumentAction::class);
        $cheque = $action->create($this->owner, $obligation, ['type' => 'cheque', 'external_reference' => 'CHQ-EXT', 'amount' => '400.0000',
            'issue_date' => now()->subDays(6)->toDateString(), 'maturity_date' => now()->subDays(3)->toDateString(), 'financial_account_id' => $this->account->id, 'notes' => null]);

        // Issued / presented: commitment only, no cash movement.
        $this->assertSame(['opening_balance'], array_column($this->journalRows(), 'type'));
        $action->present($this->owner, $cheque);
        $action->clear($this->owner, $cheque->fresh(), now()->subDays(2)->toDateString());
        $action->clear($this->owner, $cheque->fresh());           // idempotent
        $rows = $this->journalRows();
        $this->assertSame(1, collect($rows)->where('type', 'supplier_payment')->count());
        $payment = $this->findRow($rows, 'supplier_payment');
        $this->assertSame($cheque->instrument_number, $payment['instrument']['number']);
        $this->assertSame('cleared', $payment['instrument']['state']);
        $this->assertSame('600.0000', $this->acctBalance());

        app(ReverseFinanceOutgoingPaymentAction::class)->execute($this->owner, FinanceOutgoingPayment::query()->firstOrFail(), 'Chèque rejeté');
        $rows = $this->journalRows();
        $this->assertSame('returned', $this->findRow($rows, 'supplier_payment')['instrument']['state']);
        $this->assertNotNull($this->findRow($rows, 'supplier_payment_reversal'));
        $this->assertSame('1000.0000', $this->acctBalance());
        $this->assertSame('1000.0000', end($rows)['running_balance']);
    }

    public function test_legacy_rows_without_user_or_reference_render_without_failing(): void
    {
        $payment = $this->payIn('100.0000');
        $out = $this->payOut('10.0000');
        DB::table('payments')->where('id', $payment->id)->update(['received_by_user_id' => null, 'external_reference' => null, 'notes' => null]);
        DB::table('finance_outgoing_payments')->where('id', $out->id)->update(['recorded_by_user_id' => null, 'reference' => null]);
        DB::table('financial_account_opening_positions')->update(['created_by_user_id' => null, 'reference' => null]);
        $rows = $this->journalRows();

        $this->assertCount(3, $rows);
        $this->assertSame([null, null, null], array_column($rows, 'user'));
        $this->assertNull($this->findRow($rows, 'customer_payment')['external_reference']);
        $this->assertSame('1090.0000', end($rows)['running_balance']);
    }

    public function test_inactive_account_history_remains_viewable_and_reconciled(): void
    {
        $this->payIn('100.0000');
        $this->account->status = 'inactive';
        $this->account->save();

        $this->assertCount(2, $this->journalRows());
        $this->assertTrue($this->journalCtx()[1]);
        $this->actingAs($this->owner)->get(route('financial-accounts.movements', $this->account))->assertOk();
    }

    public function test_account_without_opening_position_lists_movements_but_no_running_balance(): void
    {
        $bare = $this->createFinancialAccount($this->org, ['name' => 'Bare', 'code' => 'BARE', 'type' => 'bank', 'accepted_methods' => ['cash']]);
        $this->recordPayment($this->owner, $this->order, $bare, '40.0000', ['payment_date' => now()->subDay()->toDateString()]);
        [$ctx, $running] = $this->journalCtx($bare);
        $rows = $this->journalRows([], $bare);

        $this->assertFalse($ctx['configured']);
        $this->assertFalse($running);
        $this->assertCount(1, $rows);
        $this->assertSame('unconfigured', $rows[0]['balance_scope']);
        $this->assertNull($rows[0]['running_balance']);
        $this->actingAs($this->owner)->get(route('financial-accounts.movements', $bare))->assertOk()
            ->assertInertia(fn ($page) => $page->where('running.available', false)->where('running.reason', 'not_configured')->where('position.configured', false));
    }

    public function test_cross_organization_account_is_not_found_and_never_leaks(): void
    {
        [, , , $foreignOrder, $foreignAccount] = $this->paymentFixture('100.0000');
        $this->actingAs($this->owner)->get(route('financial-accounts.movements', $foreignAccount))->assertNotFound();
        $this->actingAs($this->owner)->get(route('financial-accounts.movements.export', [$foreignAccount, 'csv']))->assertNotFound();
        // Foreign organization's rows never appear in this organization's journal even for an identical account id space.
        $this->assertSame(['opening_balance'], array_column($this->journalRows(), 'type'));
        $this->assertNotNull($foreignOrder);
    }

    public function test_cross_store_payments_are_listed_for_finance_and_store_filter_and_org_level_outgoing_payments_work(): void
    {
        $second = $this->createStore($this->org, $this->owner, 'Second');
        $secondOrder = $this->createDraftOrder($this->owner, $this->org, $second);
        $this->addCustomLine($this->owner, $secondOrder, ['unit_price_excl_tax' => '500.0000']);
        $secondOrder = app(ConfirmSalesOrderAction::class)->execute($this->owner, $secondOrder);
        $this->activate($this->owner, $this->org, $second);
        $other = $this->recordPayment($this->owner, $secondOrder, $this->account, '60.0000', ['payment_date' => now()->subDays(3)->toDateString()]);
        $this->activate($this->owner, $this->org, $this->store);
        $mine = $this->payIn('25.0000');
        $orgLevel = $this->payOut('10.0000');       // store_id NULL
        $storeLevel = $this->payOut('5.0000', null, $second->id);
        $rows = $this->journalRows();

        $this->assertEqualsCanonicalizing([$other->payment_number, $mine->payment_number, $orgLevel->payment_number, $storeLevel->payment_number],
            collect($rows)->whereNotIn('type', ['opening_balance'])->pluck('reference')->all());
        // Drill-down only where the existing PaymentPolicy allows it (active store); decided per request user.
        $page = $this->actingAs($this->owner)->get(route('financial-accounts.movements', $this->account))->assertOk()->viewData('page')['props']['rows']['data'];
        $this->assertNotNull($this->findRow($page, 'customer_payment', $mine->payment_number)['url']);
        $this->assertNull($this->findRow($page, 'customer_payment', $other->payment_number)['url']);
        $this->assertNull($this->findRow($page, 'supplier_payment', $orgLevel->payment_number)['url']);

        $filtered = $this->journalRows(['store_id' => $second->id]);
        $this->assertEqualsCanonicalizing([$other->payment_number, $storeLevel->payment_number], array_column($filtered, 'reference'));
        $this->assertNull(collect($this->journalRows(['store_id' => $second->id]))->firstWhere('reference', $orgLevel->payment_number));
        $this->assertTrue($this->journalCtx()[1]);
    }

    public function test_permissions_require_both_view_permissions_and_export_requires_finance_export(): void
    {
        $url = route('financial-accounts.movements', $this->account);
        $make = function (array $permissions) {
            $user = User::factory()->create();
            $this->addOrganizationMember($this->org, $user, $permissions, 'active', 'R'.Str::random(4));
            $this->addStoreMember($this->store, $user);
            $this->activate($user, $this->org, $this->store);

            return $user;
        };

        $this->actingAs($make(['financial_accounts.view']))->get($url)->assertForbidden();
        $this->actingAs($make(['finance.view']))->get($url)->assertForbidden();
        $this->actingAs($make([]))->get($url)->assertForbidden();
        $viewer = $make(['financial_accounts.view', 'finance.view']);
        $this->actingAs($viewer)->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('can.export', false));
        $this->actingAs($viewer)->get(route('financial-accounts.movements.export', [$this->account, 'csv']))->assertForbidden();
        $exporter = $make(['financial_accounts.view', 'finance.view', 'finance.export']);
        $this->actingAs($exporter)->get(route('financial-accounts.movements.export', [$this->account, 'csv']))->assertOk();
        $this->actingAs($make(['finance.view', 'finance.export']))->get(route('financial-accounts.movements.export', [$this->account, 'csv']))->assertForbidden();
        $this->actingAs($exporter)->get($url.'?store_id=999999')->assertNotFound();
    }

    public function test_test_mode_never_exposes_real_account_journal_data(): void
    {
        $this->payIn('100.0000');
        $this->actingAs($this->owner);
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();

        $this->get(route('financial-accounts.movements', $this->account))->assertRedirect('/test-mode');
        $this->get(route('financial-accounts.movements.export', [$this->account, 'csv']))->assertRedirect('/test-mode');
        $this->getJson(route('financial-accounts.movements', $this->account))->assertForbidden();
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'financial_account.movements_exported')->count());
    }

    public function test_pagination_is_deterministic_across_pages_and_filters_apply_server_side(): void
    {
        foreach ([18, 15, 15, 12, 10, 10, 9] as $i => $daysAgo) {
            $this->payIn((string) (10 + $i).'.0000', now()->subDays($daysAgo)->toDateString());
        }
        $this->payOut('7.0000', now()->subDays(11)->toDateString());
        $all = $this->journalRows();
        $paged = [];
        foreach ([1, 2, 3, 4] as $page) {
            $paged = array_merge($paged, $this->journalRows([], null, $page, 3));
        }

        $this->assertSame(array_column($all, 'key'), array_column($paged, 'key'));
        $this->assertSame(array_column($all, 'running_balance'), array_column($paged, 'running_balance'));
        $dates = array_column($all, 'date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
        $this->assertSame('opening_balance', $all[0]['type']);

        $this->assertCount(1, $this->journalRows(['type' => 'supplier_payment']));
        $window = $this->journalRows(['date_from' => now()->subDays(15)->toDateString(), 'date_to' => now()->subDays(11)->toDateString()]);
        $this->assertCount(4, $window);
        $ref = $all[3]['reference'];
        $this->assertSame([$ref], array_column($this->journalRows(['reference' => $ref]), 'reference'));
        $this->assertSame([], $this->journalRows(['reference' => '%']));
        // Filtered rows keep the running balance of the UNFILTERED journal order.
        $filtered = $this->journalRows(['type' => 'supplier_payment'])[0];
        $this->assertSame(collect($all)->firstWhere('key', $filtered['key'])['running_balance'], $filtered['running_balance']);
    }

    public function test_csv_and_xlsx_exports_match_the_screen_rows_and_are_audited_and_spreadsheet_safe(): void
    {
        $this->payIn('300.0000', null, ['external_reference' => '=HYPERLINK("x")']);
        $out = $this->payOut('50.0000');
        app(ReverseFinanceOutgoingPaymentAction::class)->execute($this->owner, $out, 'Erreur');
        $rows = $this->journalRows();

        $csv = $this->actingAs($this->owner)->get(route('financial-accounts.movements.export', [$this->account, 'csv']));
        $csv->assertOk();
        $lines = array_values(array_filter(preg_split('/\r?\n/', ltrim($csv->streamedContent(), "\xEF\xBB\xBF"))));
        $this->assertCount(count($rows) + 1, $lines);
        $table = array_map(fn ($l) => str_getcsv($l, ';'), $lines);
        $this->assertSame('Solde courant du journal', $table[0][8]);
        foreach ($rows as $i => $row) {
            $this->assertSame($row['reference'] ?? '', $table[$i + 1][2]);
            $this->assertSame((string) ($row['money_in'] ?? ''), $table[$i + 1][6]);
            $this->assertSame((string) ($row['money_out'] ?? ''), $table[$i + 1][7]);
            $this->assertSame((string) ($row['running_balance'] ?? ''), $table[$i + 1][8]);
        }
        $this->assertStringContainsString("'=HYPERLINK", $csv->streamedContent());
        $this->assertStringNotContainsString('idempotency', strtolower($csv->streamedContent()));

        $xlsx = $this->actingAs($this->owner)->get(route('financial-accounts.movements.export', [$this->account, 'xlsx']));
        $xlsx->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $xlsx->streamedContent());
        $reader = new XlsxReader;
        $reader->open($path);
        $sheetRows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheetRows[] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($path);
        $this->assertCount(count($rows) + 1, $sheetRows);
        foreach ($rows as $i => $row) {
            $this->assertSame($row['reference'] ?? '', (string) $sheetRows[$i + 1][2]);
            $this->assertEqualsWithDelta((float) ($row['running_balance'] ?? 0), (float) $sheetRows[$i + 1][8], 0.0001);
        }
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'financial_account.movements_exported')->count());
        $log = DB::table('audit_logs')->where('event', 'financial_account.movements_exported')->first();
        $this->assertStringNotContainsString('HYPERLINK', (string) $log->new_values);
    }

    public function test_full_journal_reconciles_with_balance_service_and_r2_across_every_movement_type(): void
    {
        $this->payIn('500.0000', now()->subDays(40)->toDateString());          // pre-opening
        $p = $this->payIn('300.0000');
        $r = $this->payIn('100.0000');
        app(ReversePaymentAction::class)->execute($this->owner, $p, 'Doublon');
        app(RefundPaymentAction::class)->execute($this->owner, $r, $this->order->fresh(), 'Retour');
        $o1 = $this->payOut('40.0000');
        $this->payOut('25.0000');
        app(ReverseFinanceOutgoingPaymentAction::class)->execute($this->owner, $o1, 'Erreur');
        [$ctx] = $this->journalCtx();
        $rec = $this->svc()->reconcile($this->org, $this->account, $ctx);

        $this->assertTrue($rec['reconciled']);
        $this->assertSame($this->acctBalance(), $rec['total']);
        $this->assertSame('975.0000', $rec['total']);
        $rows = $this->journalRows();
        $this->assertSame('975.0000', end($rows)['running_balance']);
        // R-2 (existing independent reconciliation) agrees on the same books.
        $this->artisan('finance:reconcile', ['organization' => $this->org->id])->assertSuccessful();
    }

    public function test_historical_as_of_balance_differs_from_the_journal_running_balance_inside_a_reversal_window(): void
    {
        $paid = now()->subDays(10)->toDateString();
        $payment = $this->payIn('200.0000', $paid);
        app(ReversePaymentAction::class)->execute($this->owner, $payment, 'Erreur');
        $rows = $this->journalRows();
        $original = $this->findRow($rows, 'customer_payment');
        $asOfBetween = app(FinancialAccountBalanceService::class)->organizationPosition($this->org, now()->subDays(8), $this->account->id)['accounts'][0]['balance'];

        // The existing service never counts a reversed payment; the journal running balance shows it until its reversal row.
        $this->assertSame('1000.0000', $asOfBetween);
        $this->assertSame('1200.0000', $original['running_balance']);
        $this->assertSame('1000.0000', end($rows)['running_balance']);
        $this->actingAs($this->owner)->get(route('financial-accounts.movements', $this->account))
            ->assertInertia(fn ($page) => $page->where('running.has_reversals', true)->where('running.available', true));
    }

    public function test_reading_and_exporting_never_mutate_financial_records(): void
    {
        $p = $this->payIn('100.0000');
        $this->payOut('10.0000');
        app(ReversePaymentAction::class)->execute($this->owner, $p, 'Erreur');
        $before = $this->financialSnapshot();

        $this->journalRows();
        $this->actingAs($this->owner)->get(route('financial-accounts.movements', $this->account))->assertOk();
        $this->actingAs($this->owner)->get(route('financial-accounts.movements.export', [$this->account, 'csv']))->assertOk()->streamedContent();
        $this->actingAs($this->owner)->get(route('financial-accounts.movements.export', [$this->account, 'xlsx']))->assertOk()->streamedContent();

        $this->assertEquals($before, $this->financialSnapshot());
    }

    public function test_money_is_exact_decimal_strings(): void
    {
        $this->payIn('0.1000');
        $this->payIn('0.2000');
        $rows = $this->journalRows();
        $this->assertSame('1000.3000', end($rows)['running_balance']);
        $this->assertSame(0, Decimal::compare('1000.3000', $this->acctBalance()));
    }
}
