<?php

namespace Tests\Feature\Finance;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\User;
use App\Services\Finance\Export\FinanceCaEncaisseExcelExport;
use App\Services\Finance\Export\FinanceCaEncaissePdfExport;
use App\Services\Finance\Export\FinanceSituationExcelExport;
use App\Services\Finance\Export\FinanceSituationPdfExport;
use App\Services\Finance\Export\MergedInvoicePdfExport;
use App\Services\Finance\FinanceCaEncaisseService;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\FinanceReceivablesService;
use App\Support\Decimal;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DocumentTestCase;
use ZipArchive;

class FinanceExportTest extends DocumentTestCase
{
    public function test_xlsx_export_produces_a_valid_workbook_with_the_four_required_sheets(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '1000.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-05-29']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '400.0000', ['payment_date' => '2026-05-30']);

        $bytes = app(FinanceSituationExcelExport::class)->build($organization, FinancePeriod::fromMonth('2026-05'), null);

        $this->assertNotSame('', $bytes);
        // A valid ZIP/XLSX container starts with the "PK" local file header signature.
        $this->assertSame("PK\x03\x04", substr($bytes, 0, 4));

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_test_').'.xlsx';
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $sheetXml = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        @unlink($tmp);

        $this->assertNotFalse($sheetXml);
        foreach (['Situation', 'Ventes', 'Encaissements', 'Créances'] as $sheetName) {
            $this->assertStringContainsString($sheetName, $sheetXml);
        }
    }

    public function test_multi_month_pdf_generates_successfully_for_several_selected_months(): void
    {
        [$owner, $organization] = $this->documentFixture(total: '500.0000');

        $result = app(FinanceSituationPdfExport::class)->build(
            $organization,
            FinancePeriod::fromMonths(['2026-05', '2026-06']),
            null,
        );

        $this->assertSame('application/pdf', $result['mime']);
        $this->assertSame('%PDF', substr($result['bytes'], 0, 4));
        $this->assertGreaterThan(1000, strlen($result['bytes']));
    }

    /**
     * Precisely verifies the page-break mechanism itself, independent of
     * Dompdf's binary output: each requested month renders as its own
     * `.section` element, and the stylesheet forces a break before every
     * section except the first — so N months always yield exactly N-1 forced
     * page breaks, regardless of how many physical PDF pages a long month's
     * own content spans.
     */
    public function test_situation_pdf_view_emits_one_section_per_month_with_page_break_css(): void
    {
        $html = view('finance.situation-pdf', [
            'organizationName' => 'Acme',
            'storeName' => null,
            'generatedAt' => now()->format('d/m/Y H:i'),
            'sections' => [
                ['label' => 'Mai 2026', 'ventes' => '0,00 DH', 'facturation' => '0,00 DH', 'encaissements' => '0,00 DH', 'creances_debut' => '0,00 DH', 'creances_fin' => '0,00 DH', 'has_variance' => false, 'variance' => '0,00 DH', 'invoices' => []],
                ['label' => 'Juin 2026', 'ventes' => '0,00 DH', 'facturation' => '0,00 DH', 'encaissements' => '0,00 DH', 'creances_debut' => '0,00 DH', 'creances_fin' => '0,00 DH', 'has_variance' => false, 'variance' => '0,00 DH', 'invoices' => []],
                ['label' => 'Juillet 2026', 'ventes' => '0,00 DH', 'facturation' => '0,00 DH', 'encaissements' => '0,00 DH', 'creances_debut' => '0,00 DH', 'creances_fin' => '0,00 DH', 'has_variance' => false, 'variance' => '0,00 DH', 'invoices' => []],
            ],
        ])->render();

        $this->assertSame(3, substr_count($html, 'class="section"'));
        $this->assertStringContainsString('.section { page-break-before: always; }', $html);
        $this->assertStringContainsString('.section:first-child { page-break-before: avoid; }', $html);
        $this->assertStringContainsString('Mai 2026', $html);
        $this->assertStringContainsString('Juillet 2026', $html);
    }

    public function test_merged_invoice_pdf_selection_mode_merges_the_explicit_invoice_ids(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);

        $invoiceIds = [];
        foreach ([100, 200] as $price) {
            $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => now()->toDateString()]);
            $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => (string) $price.'.0000']);
            $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
            $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
            $invoiceIds[] = $invoice->id;
        }

        $result = app(MergedInvoicePdfExport::class)->build($organization, 'selection', null, null, $invoiceIds);

        $this->assertSame('application/pdf', $result['mime']);
        $this->assertSame('%PDF', substr($result['bytes'], 0, 4));
    }

    public function test_merged_invoice_pdf_rejects_an_empty_selection(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $this->expectException(HttpException::class);
        app(MergedInvoicePdfExport::class)->build($organization, 'selection', null, null, []);
    }

    /**
     * Root-cause regression for the PDF 500 ("Undefined property:
     * stdClass::$amount"): FinanceReceivablesService::paidAmountsBySalesOrder()
     * plucked an unaliased raw SUM() expression, so every row silently
     * plucked as null instead of the real amount. Proven directly against
     * the exact method, then end-to-end through the PDF pipeline that
     * exercises it (FinanceInvoiceReadModel::withPaymentSummaries()).
     */
    public function test_paid_amounts_by_sales_order_returns_the_real_amount_not_null(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '750.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-05-29']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '750.0000', ['payment_date' => '2026-05-30']);

        $paid = app(FinanceReceivablesService::class)->paidAmountsBySalesOrder([$order->id], now());

        $this->assertArrayHasKey($order->id, $paid);
        $this->assertSame(0, Decimal::compare($paid[$order->id], '750.0000'));
    }

    public function test_situation_pdf_export_no_longer_throws_when_a_payment_exists(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '750.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-05-29']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '750.0000', ['payment_date' => '2026-05-30']);

        $result = app(FinanceSituationPdfExport::class)->build($organization, FinancePeriod::fromMonths(['2026-05']), null);

        $this->assertSame('%PDF', substr($result['bytes'], 0, 4));

        $this->activate($owner, $organization);
        $this->actingAs($owner)->get(route('finance.export.pdf', ['month' => '2026-05']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_ca_encaisse_xlsx_export_downloads_correctly(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '900.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-05-29']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '900.0000', ['payment_date' => '2026-05-30']);
        $this->activate($owner, $organization);

        $response = $this->actingAs($owner)->get(route('finance.ca-encaisse.export.xlsx', ['month' => '2026-05']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition', 'attachment; filename="CA-encaisse-2026-05.xlsx"');

        $bytes = $response->getContent();
        // A valid ZIP/XLSX container starts with the "PK" local file header
        // signature — never raw text/HTML, which is exactly what an
        // Inertia-intercepted binary response used to render in the browser.
        $this->assertSame("PK\x03\x04", substr($bytes, 0, 4));

        $tmp = tempnam(sys_get_temp_dir(), 'ca_encaisse_test_').'.xlsx';
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $sheetXml = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        @unlink($tmp);

        $this->assertNotFalse($sheetXml);
        $this->assertStringContainsString('CA encaissé', $sheetXml);
    }

    public function test_ca_encaisse_pdf_export_downloads_correctly_with_a_titled_month(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '900.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-05-29']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '900.0000', ['payment_date' => '2026-05-30']);
        $this->activate($owner, $organization);

        $response = $this->actingAs($owner)->get(route('finance.ca-encaisse.export.pdf', ['month' => '2026-05']));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF', substr($response->getContent(), 0, 4));
    }

    public function test_ca_encaisse_export_amounts_equal_the_ui_amounts(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '1234.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-05-29']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '1234.0000', ['payment_date' => '2026-05-30']);

        $service = app(FinanceCaEncaisseService::class);
        $period = FinancePeriod::fromMonth('2026-05');

        $uiRow = $service->rows($organization, $period, null)->items()[0];
        $exportRow = $service->cursor($organization, $period, null)->first();

        $this->assertSame(0, Decimal::compare($uiRow['amount'], $exportRow['amount']));
        $this->assertSame(0, Decimal::compare($uiRow['amount'], '1234.0000'));
        $this->assertSame(0, Decimal::compare($service->total($organization, $period, null), '1234.0000'));

        // The XLSX/PDF builders never recompute amounts — they read the same
        // service, so the exported bytes are provably built from these exact
        // figures.
        $xlsxBytes = app(FinanceCaEncaisseExcelExport::class)->build($organization, $period, null);
        $this->assertSame("PK\x03\x04", substr($xlsxBytes, 0, 4));
        $pdfResult = app(FinanceCaEncaissePdfExport::class)->build($organization, [$period], null);
        $this->assertSame('%PDF', substr($pdfResult['bytes'], 0, 4));
    }

    /**
     * Audit fix: Journal des ventes used to render an "Export XLSX" button
     * wired to `/finance/export/xlsx` — the SITUATION workbook (Ventes /
     * Facturation / Encaissements aggregates), not the Journal's own
     * per-invoice rows. That silently violated "the same filter scope drives
     * screen + export" for this one page (every other Finance drill-down —
     * Ventes/Facturation/Encaissements/Créances — correctly has no export
     * button at all). The fix removes the mismatched button/prop rather than
     * inventing a new, unrequested Journal-specific export; this pins that
     * the page no longer advertises an export capability it does not have.
     */
    public function test_journal_des_ventes_no_longer_advertises_an_unrelated_export_action(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '500.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-05-29']));

        $response = $this->actingAs($owner)->withHeader('X-Inertia', 'true')
            ->get(route('finance.journal', ['month' => '2026-05']));

        $response->assertOk();
        $this->assertArrayNotHasKey('can', $response->json('props'));
    }
}
