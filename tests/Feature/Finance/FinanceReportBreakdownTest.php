<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceObligationAction;
use App\Actions\Finance\RecordFinanceOutgoingPaymentAction;
use App\Actions\Finance\ReverseFinanceOutgoingPaymentAction;
use App\Actions\Finance\SetFinancialAccountOpeningPositionAction;
use App\Models\FinanceObligation;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\Export\FinanceMonthlySituationExcelExport;
use App\Services\Finance\FinanceMonthlySituationService;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\FinanceTreasuryForecastService;
use App\Support\Decimal;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\PaymentTestCase;

/** FIN-EXPORT-003: current month / carried forward / inclusive breakdown shared by the page and the Excel export. */
class FinanceReportBreakdownTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withFreshAuthentication(level: 1);
        $this->travelTo('2026-12-10 12:00:00');
    }

    public function test_qa_reference_december_breakdown_forecast_ui_and_excel_agree(): void
    {
        [$owner, $organization, $store, $account] = $this->fixture('2101.0000');
        $one = $this->obligation($owner, $organization, ['due_date' => '2026-11-15']);
        $this->obligation($owner, $organization, ['due_date' => '2026-11-20']);
        $this->obligation($owner, $organization, ['due_date' => '2026-12-20']);
        $this->pay($owner, $organization, $account, $one, '50.0000', '2026-12-05');
        $period = FinancePeriod::fromMonth('2026-12');

        $breakdown = app(FinanceMonthlySituationService::class)->breakdown($organization, $period, null);
        $this->assertSame(['planned' => '120.0000', 'paid' => '0.0000', 'remaining' => '120.0000', 'count' => 1], $breakdown['current_month']);
        $this->assertSame(['planned' => '240.0000', 'paid' => '50.0000', 'remaining' => '190.0000', 'count' => 2], $breakdown['carried_forward']);
        $this->assertSame(['planned' => '360.0000', 'paid' => '50.0000', 'remaining' => '310.0000', 'count' => 3], $breakdown['inclusive']);

        // Existing treasury forecast is untouched and agrees with the inclusive remaining.
        $forecast = app(FinanceTreasuryForecastService::class)->forecast($organization, $period, null);
        $this->assertSame('2051.0000', $forecast['balance']['total']);
        $this->assertSame('310.0000', $forecast['remaining_outflows']);
        $this->assertSame('1741.0000', $forecast['projected']);

        // Existing KPI values keep their meaning (current-month obligations only).
        $kpis = app(FinanceMonthlySituationService::class)->kpis($organization, $period, null);
        $this->assertSame(['120.0000', '0.0000', '120.0000'], [$kpis['planned'], $kpis['paid'], $kpis['remaining']]);

        // UI props.
        $this->actingAs($owner)->get(route('finance.monthly-situation.index', ['month' => '2026-12']))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('breakdown.current_month.remaining', '120.0000')
                ->where('breakdown.carried_forward.planned', '240.0000')
                ->where('breakdown.carried_forward.paid', '50.0000')
                ->where('breakdown.inclusive.remaining', '310.0000')
                ->where('treasury.projected', '1741.0000'));

        // Excel carries the very same numbers.
        $sheet = $this->situationSheet($organization, $period, null);
        $this->assertEquals([120.0, 0.0, 120.0], $this->excelBucket($sheet, 'Échéances du mois'));
        $this->assertEquals([240.0, 50.0, 190.0], $this->excelBucket($sheet, 'Reports des périodes précédentes'));
        $this->assertEquals([360.0, 50.0, 310.0], $this->excelBucket($sheet, 'Total restant jusqu’à fin de période'));
        $this->assertEquals(1741.0, collect($sheet)->firstWhere(0, 'Prévision fin de mois')[1]);
        $this->assertStringContainsString($period->label(), (string) $this->scopeText($sheet));
    }

    public function test_current_month_only_unpaid_partial_paid_and_reversed_payments(): void
    {
        [$owner, $organization, , $account] = $this->fixture();
        $partial = $this->obligation($owner, $organization, ['due_date' => '2026-12-05', 'planned_amount' => '200.0000']);
        $reversed = $this->obligation($owner, $organization, ['due_date' => '2026-12-06', 'planned_amount' => '100.0000']);
        $this->obligation($owner, $organization, ['due_date' => '2026-12-07', 'planned_amount' => '50.0000']);
        $this->pay($owner, $organization, $account, $partial, '80.0000', '2026-12-08');
        $payment = $this->pay($owner, $organization, $account, $reversed, '100.0000', '2026-12-08');
        app(ReverseFinanceOutgoingPaymentAction::class)->execute($owner, $payment, 'Rejeté');

        $breakdown = app(FinanceMonthlySituationService::class)->breakdown($organization, FinancePeriod::fromMonth('2026-12'), null);
        $this->assertSame(['350.0000', '80.0000', '270.0000'], [$breakdown['current_month']['planned'], $breakdown['current_month']['paid'], $breakdown['current_month']['remaining']]);
        $this->assertSame('0.0000', $breakdown['carried_forward']['planned']);
        $this->assertSame($breakdown['current_month']['remaining'], $breakdown['inclusive']['remaining']);
    }

    public function test_previous_month_unpaid_and_partial_are_carried_but_settled_and_cancelled_are_not(): void
    {
        [$owner, $organization, , $account] = $this->fixture();
        $this->obligation($owner, $organization, ['due_date' => '2026-10-20', 'planned_amount' => '100.0000']);
        $partial = $this->obligation($owner, $organization, ['due_date' => '2026-11-02', 'planned_amount' => '100.0000']);
        $settled = $this->obligation($owner, $organization, ['due_date' => '2026-11-03', 'planned_amount' => '100.0000']);
        $cancelled = $this->obligation($owner, $organization, ['due_date' => '2026-11-04', 'planned_amount' => '100.0000']);
        $this->pay($owner, $organization, $account, $partial, '30.0000', '2026-11-10');
        $this->pay($owner, $organization, $account, $settled, '100.0000', '2026-11-10');
        app(ManageFinanceObligationAction::class)->cancel($owner, $cancelled, 'Annulée');

        $breakdown = app(FinanceMonthlySituationService::class)->breakdown($organization, FinancePeriod::fromMonth('2026-12'), null);
        $this->assertSame(['200.0000', '30.0000', '170.0000', 2], [$breakdown['carried_forward']['planned'], $breakdown['carried_forward']['paid'], $breakdown['carried_forward']['remaining'], $breakdown['carried_forward']['count']]);
        $this->assertSame('0.0000', $breakdown['current_month']['planned']);
    }

    public function test_no_obligations_and_future_obligations_are_excluded(): void
    {
        [$owner, $organization] = $this->fixture();
        $service = app(FinanceMonthlySituationService::class);
        $empty = $service->breakdown($organization, FinancePeriod::fromMonth('2026-12'), null);
        foreach (['current_month', 'carried_forward', 'inclusive'] as $key) {
            $this->assertSame(['planned' => '0.0000', 'paid' => '0.0000', 'remaining' => '0.0000', 'count' => 0], $empty[$key]);
        }

        $this->obligation($owner, $organization, ['due_date' => '2027-01-05', 'planned_amount' => '500.0000']);
        $future = $service->breakdown($organization, FinancePeriod::fromMonth('2026-12'), null);
        $this->assertSame('0.0000', $future['inclusive']['planned']);
        $this->assertSame('0.0000', $future['inclusive']['remaining']);
    }

    public function test_past_period_report_and_payment_date_semantics(): void
    {
        [$owner, $organization, , $account] = $this->fixture();
        $october = $this->obligation($owner, $organization, ['due_date' => '2026-10-15', 'planned_amount' => '100.0000']);
        $this->obligation($owner, $organization, ['due_date' => '2026-11-15', 'planned_amount' => '100.0000']);
        // Paid in December: the report uses posted payments as of now (payment date is not a cutoff), like every other obligation figure.
        $this->pay($owner, $organization, $account, $october, '40.0000', '2026-12-05');

        $november = app(FinanceMonthlySituationService::class)->breakdown($organization, FinancePeriod::fromMonth('2026-11'), null);
        $this->assertSame(['100.0000', '0.0000', '100.0000'], [$november['current_month']['planned'], $november['current_month']['paid'], $november['current_month']['remaining']]);
        $this->assertSame(['100.0000', '40.0000', '60.0000'], [$november['carried_forward']['planned'], $november['carried_forward']['paid'], $november['carried_forward']['remaining']]);
        $this->assertSame('160.0000', $november['inclusive']['remaining']);
        // A closed (past) month keeps the existing historical forecast mode.
        $this->assertSame('historical', app(FinanceTreasuryForecastService::class)->forecast($organization, FinancePeriod::fromMonth('2026-11'), null)['mode']);
    }

    public function test_supplier_summary_reconciles_with_inclusive_breakdown(): void
    {
        [$owner, $organization, , $account] = $this->fixture();
        $a = $this->obligation($owner, $organization, ['due_date' => '2026-11-15', 'beneficiary_name' => 'Fournisseur A']);
        $this->obligation($owner, $organization, ['due_date' => '2026-12-15', 'beneficiary_name' => 'Fournisseur B', 'planned_amount' => '75.5000']);
        $this->pay($owner, $organization, $account, $a, '20.0000', '2026-12-02');
        $period = FinancePeriod::fromMonth('2026-12');
        $service = app(FinanceMonthlySituationService::class);

        $summary = collect($service->supplierSummary($organization, $period, null));
        $inclusive = $service->breakdown($organization, $period, null)['inclusive'];
        $sum = fn (string $field) => $summary->reduce(fn (string $carry, array $row) => Decimal::add($carry, $row[$field]), '0.0000');
        $this->assertSame($inclusive['planned'], $sum('planned'));
        $this->assertSame($inclusive['paid'], $sum('paid'));
        $this->assertSame($inclusive['remaining'], $sum('remaining'));
    }

    public function test_breakdown_is_isolated_by_organization_and_store(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        $other = $this->createStore($organization, $owner, 'Autre');
        $this->obligation($owner, $organization, ['due_date' => '2026-12-15', 'store_id' => $store->id, 'planned_amount' => '100.0000']);
        $this->obligation($owner, $organization, ['due_date' => '2026-11-15', 'store_id' => $other->id, 'planned_amount' => '40.0000']);
        [$ownerB, $organizationB] = $this->fixture();
        $this->obligation($ownerB, $organizationB, ['due_date' => '2026-12-15', 'planned_amount' => '9999.0000']);
        $period = FinancePeriod::fromMonth('2026-12');
        $service = app(FinanceMonthlySituationService::class);

        $all = $service->breakdown($organization, $period, null);
        $this->assertSame('140.0000', $all['inclusive']['planned']);
        $scoped = $service->breakdown($organization, $period, $store);
        $this->assertSame(['100.0000', '0.0000'], [$scoped['inclusive']['planned'], $scoped['carried_forward']['planned']]);
        $this->assertSame('9999.0000', $service->breakdown($organizationB, $period, null)['inclusive']['planned']);
        $this->assertSame(FinanceObligation::query()->withoutGlobalScopes()->count(), 3);
    }

    /** @return array{User,Organization,Store,FinancialAccount} */
    private function fixture(string $opening = '1000.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);
        $account = $this->createFinancialAccount($organization, ['type' => 'bank', 'code' => 'BANK-'.Str::upper(Str::random(5)), 'accepted_methods' => ['bank_transfer']]);
        app(SetFinancialAccountOpeningPositionAction::class)->execute($owner, $account, ['effective_date' => '2026-01-01', 'amount' => $opening, 'currency_code' => 'MAD']);

        return [$owner, $organization, $store, $account];
    }

    private function obligation(User $owner, Organization $organization, array $overrides = []): FinanceObligation
    {
        return app(ManageFinanceObligationAction::class)->create($owner, $organization, array_replace([
            'store_id' => null, 'category' => 'rent', 'label' => 'Loyer', 'supplier_id' => null, 'beneficiary_name' => 'Bailleur', 'reference' => null,
            'due_date' => '2026-12-15', 'planned_amount' => '120.0000', 'currency_code' => 'MAD', 'planned_payment_method' => 'bank_transfer',
            'preferred_financial_account_id' => null, 'notes' => null,
        ], $overrides));
    }

    private function pay(User $owner, Organization $organization, FinancialAccount $account, FinanceObligation $obligation, string $amount, string $date)
    {
        return app(RecordFinanceOutgoingPaymentAction::class)->execute($owner, $organization, [
            'store_id' => null, 'financial_account_id' => $account->id, 'supplier_id' => null, 'beneficiary_name' => 'Bénéficiaire',
            'payment_method' => 'bank_transfer', 'amount' => $amount, 'currency_code' => 'MAD', 'payment_date' => $date,
            'reference' => null, 'notes' => null, 'idempotency_key' => (string) Str::uuid(),
            'allocations' => [['obligation_id' => $obligation->id, 'amount' => $amount]],
        ]);
    }

    /** @return array<int,array<int,mixed>> */
    private function situationSheet(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $bytes = app(FinanceMonthlySituationExcelExport::class)->build($organization, $period, $store, []);
        $path = tempnam(sys_get_temp_dir(), 'breakdown_').'.xlsx';
        file_put_contents($path, $bytes);
        try {
            $reader = new Reader;
            $reader->open($path);
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== 'Situation') {
                    continue;
                }
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
            }
            $reader->close();

            return $rows;
        } finally {
            @unlink($path);
        }
    }

    /** @return array{float,float,float} */
    private function excelBucket(array $sheet, string $label): array
    {
        $row = collect($sheet)->first(fn (array $row) => ($row[3] ?? null) === $label);
        $this->assertNotNull($row, "Excel row '{$label}' missing");

        return [$row[4], $row[5], $row[6]];
    }

    private function scopeText(array $sheet): ?string
    {
        $row = collect($sheet)->first(fn (array $row) => str_starts_with((string) ($row[3] ?? ''), 'Périmètre'));

        return $row[3] ?? null;
    }
}
