<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceExpectedCollectionAction;
use App\Actions\Finance\ManageFinanceMonthReviewAction;
use App\Actions\Finance\ManageFinanceObligationAction;
use App\Models\FinanceMonthReview;
use App\Models\User;
use App\Services\Finance\Export\FinanceMonthlySituationExcelExport;
use App\Services\Finance\FinanceMonthReviewService;
use App\Services\Finance\FinancePeriod;
use App\Services\OrganizationBackups\OrganizationBackupSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\PaymentTestCase;
use ZipArchive;

class FinanceMonthReviewExportTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withFreshAuthentication(level: 1);
        $this->travelTo('2026-10-17 12:00:00');
    }

    public function test_review_close_and_reopen_are_audited_without_blocking_outstanding_obligations(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        app(ManageFinanceObligationAction::class)->create($owner, $organization, $this->obligation(['store_id' => $store->id, 'due_date' => '2026-09-15']));
        $action = app(ManageFinanceMonthReviewAction::class);
        $period = FinancePeriod::fromMonth('2026-09');
        $review = $action->review($owner, $organization, $period, 'Contrôle effectué');
        $this->assertSame('reviewed', $review->status);
        $closed = $action->close($owner, $organization, $period, 'Validé avec reste à payer', true);
        $this->assertSame('closed', $closed->status);
        $this->assertSame('1000.0000', $closed->close_snapshot['remaining']);
        $reopened = $action->reopen($owner, $organization, $period, 'Paiement reçu tardivement');
        $this->assertSame('open', $reopened->status);
        $this->assertSame(['finance_month.reviewed','finance_month.closed','finance_month.reopened'], DB::table('audit_logs')->where('organization_id', $organization->id)->whereIn('event', ['finance_month.reviewed','finance_month.closed','finance_month.reopened'])->orderBy('id')->pluck('event')->all());
    }

    public function test_future_month_cannot_be_reviewed_or_closed_and_reopen_requires_reason_and_fresh_auth(): void
    {
        [$owner, $organization] = $this->tenant();
        $action = app(ManageFinanceMonthReviewAction::class);
        foreach (['review','close'] as $method) {
            try {
                $method === 'review'
                    ? $action->review($owner, $organization, FinancePeriod::fromMonth('2026-11'), null)
                    : $action->close($owner, $organization, FinancePeriod::fromMonth('2026-10'), null, true);
                $this->fail('Expected month restriction.');
            } catch (ValidationException) {}
        }

        $action->close($owner, $organization, FinancePeriod::fromMonth('2026-09'), null, true);
        $this->actingAs($owner)->post(route('finance.monthly-situation.reopen'), ['month' => '2026-09', 'reason' => ''])->assertSessionHasErrors('reason');
        session()->forget('auth.fresh.account_confirmed_at');
        $this->post(route('finance.monthly-situation.reopen'), ['month' => '2026-09', 'reason' => 'Correction'])->assertRedirect(route('security.confirm'));
        $this->assertSame('closed', FinanceMonthReview::query()->firstOrFail()->status);
    }

    public function test_close_warnings_require_explicit_acknowledgement_and_stale_changes_are_detected(): void
    {
        [$owner, $organization] = $this->tenant();
        $this->createFinancialAccount($organization, ['code' => 'UNCONFIGURED']);
        $period = FinancePeriod::fromMonth('2026-09');
        $action = app(ManageFinanceMonthReviewAction::class);
        try { $action->close($owner, $organization, $period, null, false); $this->fail('Expected acknowledgement.'); } catch (ValidationException) {}
        $row = $action->close($owner, $organization, $period, null, true);
        $this->travel(1)->minute();
        app(ManageFinanceObligationAction::class)->create($owner, $organization, $this->obligation(['due_date' => '2026-09-20']));
        $state = app(FinanceMonthReviewService::class)->state($organization, $period);
        $this->assertTrue($state['stale']);
        $this->assertContains('opening_positions', collect($state['warnings'])->pluck('key')->all());
        $this->assertNotNull($row->close_snapshot);
    }

    public function test_review_and_export_permissions_and_tenant_scope_are_enforced(): void
    {
        [$ownerA, $organizationA, $storeA] = $this->tenant();
        [$ownerB, $organizationB] = $this->tenant();
        $viewer = User::factory()->create();
        $this->addOrganizationMember($organizationA, $viewer, ['finance.monthly_situation.view']);
        $this->activate($viewer, $organizationA, $storeA);
        $this->actingAs($viewer)->post(route('finance.monthly-situation.review'), ['month' => '2026-09'])->assertForbidden();
        $this->actingAs($viewer)->get(route('finance.monthly-situation.export.xlsx', ['month' => '2026-09']))->assertForbidden();

        try { app(ManageFinanceMonthReviewAction::class)->review($ownerB, $organizationA, FinancePeriod::fromMonth('2026-09'), null); $this->fail('Expected tenant denial.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(403, $exception->getStatusCode()); }
        $this->assertDatabaseMissing('finance_month_reviews', ['organization_id' => $organizationB->id, 'period' => '2026-09-01']);
        $this->assertNotSame($ownerA->id, $ownerB->id);
    }

    public function test_export_contains_expected_sheets_numeric_money_real_dates_and_no_fake_balance(): void
    {
        [$owner, $organization, $store, $order] = $this->paymentFixture('2000.0000');
        app(ManageFinanceObligationAction::class)->create($owner, $organization, $this->obligation(['store_id' => $store->id, 'planned_amount' => '1250.5000']));
        app(ManageFinanceExpectedCollectionAction::class)->create($owner, $organization, ['sales_order_id'=>$order->id,'label'=>'Client','reference'=>'EXP-1','expected_date'=>'2026-10-20','expected_amount'=>'500.0000','notes'=>null]);
        $bytes = app(FinanceMonthlySituationExcelExport::class)->build($organization, FinancePeriod::fromMonth('2026-10'), $store, []);
        $this->assertSame("PK\x03\x04", substr($bytes, 0, 4));
        $path = tempnam(sys_get_temp_dir(), 'm1f_').'.xlsx'; file_put_contents($path, $bytes);
        try {
            $sheets = $this->readXlsx($path);
            $this->assertSame(['Situation','Fournisseurs','Charges récurrentes','Échéances futures','Encaissements attendus'], array_keys($sheets));
            $this->assertSame('Solde non configuré', collect($sheets['Situation'])->firstWhere(0, 'Solde disponible')[1]);
            $detail = collect($sheets['Situation'])->first(fn (array $row) => is_string($row[1] ?? null) && str_starts_with($row[1], 'OBL-'));
            $this->assertInstanceOf(\DateTimeInterface::class, $detail[0]);
            $this->assertIsFloat($detail[6]);
            $expected = collect($sheets['Encaissements attendus'])->first(fn (array $row) => ($row[2] ?? null) === $order->order_number);
            $this->assertEquals(500.0, $expected[4]);
        } finally { @unlink($path); }
    }

    public function test_export_route_respects_store_scope_and_permission_and_backup_includes_reviews(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $other = $this->createStore($organization, $owner, 'Autre');
        app(ManageFinanceObligationAction::class)->create($owner, $organization, $this->obligation(['store_id'=>$store->id,'label'=>'Visible']));
        app(ManageFinanceObligationAction::class)->create($owner, $organization, $this->obligation(['store_id'=>$other->id,'label'=>'Masquée']));
        $response = $this->actingAs($owner)->get(route('finance.monthly-situation.export.xlsx', ['month'=>'2026-10','store_id'=>$store->id]))->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('situation-fin-de-mois-2026-10.xlsx', $disposition);

        $bytes = $response->streamedContent();
        $this->assertSame("PK\x03\x04", substr($bytes, 0, 4));
        $path = tempnam(sys_get_temp_dir(), 'monthly_download_').'.xlsx';
        file_put_contents($path, $bytes);
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
            $zip->close();
            $this->assertSame(
                ['Situation','Fournisseurs','Charges récurrentes','Échéances futures','Encaissements attendus'],
                array_keys($this->readXlsx($path)),
            );
        } finally {
            @unlink($path);
        }
        $this->assertContains('finance_month_reviews', app(OrganizationBackupSchema::class)->tenantTables());
    }

    private function tenant(): array
    {
        $owner = User::factory()->create(); $organization = $this->createOrganization($owner); $store = $this->createStore($organization, $owner); $this->activate($owner, $organization, $store);
        return [$owner, $organization, $store];
    }

    private function obligation(array $overrides = []): array
    {
        return array_replace(['store_id'=>null,'category'=>'rent','label'=>'Loyer','supplier_id'=>null,'beneficiary_name'=>'Bailleur','reference'=>'LOYER','due_date'=>'2026-10-10','planned_amount'=>'1000.0000','currency_code'=>'MAD','planned_payment_method'=>null,'preferred_financial_account_id'=>null,'notes'=>null], $overrides);
    }

    /** @return array<string,array<int,array<int,mixed>>> */
    private function readXlsx(string $path): array
    {
        $reader = new Reader; $reader->open($path); $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) foreach ($sheet->getRowIterator() as $row) $sheets[$sheet->getName()][] = $row->toArray();
        $reader->close(); return $sheets;
    }
}
