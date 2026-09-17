<?php

namespace Tests\Feature\Finance;

use App\Contracts\PdfGenerator;
use App\Models\User;
use App\Services\DocumentSellerProfile;
use App\Services\Finance\Export\FinanceCaEncaisseExcelExport;
use App\Services\Finance\Export\FinanceCaEncaissePdfExport;
use App\Services\Finance\FinanceCaEncaisseService;
use App\Services\Finance\FinancePeriod;
use App\Support\Decimal;
use Mockery;
use Tests\Support\DocumentTestCase;
use ZipArchive;

/**
 * CA encaissé export presentation: PDF orientation, print layout, company
 * branding sourced from the existing Document Profile, and XLSX styling.
 * None of this touches the underlying CA encaissé business rule or amounts
 * — see FinanceCaEncaisseServiceTest for that. This suite only proves the
 * display polish is correct and that it never regresses the figures.
 */
class FinanceCaEncaissePdfLayoutTest extends DocumentTestCase
{
    public function test_pdf_export_defaults_to_landscape_orientation(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '500.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-05-29']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '500.0000', ['payment_date' => '2026-05-30']);
        $this->activate($owner, $organization);

        $captured = $this->captureGeneratorOptions();

        $this->actingAs($owner)->get(route('finance.ca-encaisse.export.pdf', ['month' => '2026-05']))->assertOk();

        $this->assertSame('landscape', $captured->options['orientation'] ?? null);
    }

    public function test_pdf_export_accepts_an_explicit_portrait_orientation(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '500.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-05-29']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '500.0000', ['payment_date' => '2026-05-30']);
        $this->activate($owner, $organization);

        $captured = $this->captureGeneratorOptions();

        $this->actingAs($owner)->get(route('finance.ca-encaisse.export.pdf', ['month' => '2026-05', 'orientation' => 'portrait']))
            ->assertOk();

        $this->assertSame('portrait', $captured->options['orientation'] ?? null);
    }

    public function test_pdf_export_rejects_an_arbitrary_orientation_value(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->getJson(route('finance.ca-encaisse.export.pdf', ['month' => '2026-05', 'orientation' => 'sideways']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('orientation');
    }

    public function test_dates_are_present_and_never_wrap_in_the_rendered_pdf_source(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '11853.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-09-12']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '11853.0000', ['payment_date' => '2026-09-12']);

        $result = app(FinanceCaEncaissePdfExport::class)->build($organization, [FinancePeriod::fromMonth('2026-09')], null);
        $this->assertSame('%PDF', substr($result['bytes'], 0, 4));

        // Render the exact same view directly to inspect the HTML source for
        // the formatted date and the nowrap rule that keeps it on one line —
        // the original bug this polish fixes.
        $seller = app(DocumentSellerProfile::class)->organizationIdentity($organization);
        $html = view('finance.ca-encaisse-pdf', [
            'seller' => $seller,
            'storeName' => null,
            'generatedAt' => now()->format('d/m/Y H:i'),
            'sections' => [[
                'label' => 'Septembre 2026',
                'total' => '11 853,00',
                'rows' => [[
                    'payment_number' => 'PAY-000001',
                    'sale_date' => '12/09/2026',
                    'payment_date' => '12/09/2026',
                    'reference' => '1/2026',
                    'lines' => [['quantity' => '1', 'designation' => 'Consulting', 'reference' => null]],
                    'customer' => 'Client SARL',
                    'method_label' => 'Espèces',
                    'amount' => '11 853,00',
                    'status_label' => 'Paiement comptant',
                ]],
            ]],
        ])->render();

        $this->assertStringContainsString('12/09/2026', $html);
        $this->assertStringContainsString('td.nowrap { white-space: nowrap; }', $html);
        $this->assertStringContainsString('CA encaissé du mois', $html);
    }

    public function test_document_profile_identity_appears_in_the_pdf_header_when_configured(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $organization->settings = ['document_profile' => [
            'legal_name' => 'Atelier Test SARL',
            'address' => '12 Rue des Fleurs, Casablanca',
            'phone' => '0522000000',
            'tax_identifier' => 'ICE-999',
            'registration_number' => 'RC-42',
        ]];
        $organization->save();

        $seller = app(DocumentSellerProfile::class)->organizationIdentity($organization->fresh());
        $html = view('finance.ca-encaisse-pdf', [
            'seller' => $seller,
            'storeName' => null,
            'generatedAt' => now()->format('d/m/Y H:i'),
            'sections' => [['label' => 'Mai 2026', 'total' => '0,00', 'rows' => []]],
        ])->render();

        $this->assertStringContainsString('Atelier Test SARL', $html);
        $this->assertStringContainsString('12 Rue des Fleurs, Casablanca', $html);
        $this->assertStringContainsString('ICE : ICE-999', $html);
        $this->assertStringContainsString('RC : RC-42', $html);
    }

    public function test_missing_logo_and_profile_fields_render_cleanly_without_blank_labels(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        // Deliberately: no document_profile configured at all.

        $seller = app(DocumentSellerProfile::class)->organizationIdentity($organization);
        $html = view('finance.ca-encaisse-pdf', [
            'seller' => $seller,
            'storeName' => null,
            'generatedAt' => now()->format('d/m/Y H:i'),
            'sections' => [['label' => 'Mai 2026', 'total' => '0,00', 'rows' => []]],
        ])->render();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('ICE :', $html);
        $this->assertStringNotContainsString('RC :', $html);
        $this->assertStringNotContainsString('TP :', $html);
        // Falls back to the organization's own name (DocumentSellerProfile's
        // own guarantee — never a blank company header).
        $this->assertStringContainsString($organization->name, $html);
    }

    public function test_xlsx_contains_title_period_headers_data_and_total(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '2000.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-05-29']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '2000.0000', ['payment_date' => '2026-05-30']);

        $bytes = app(FinanceCaEncaisseExcelExport::class)->build($organization, FinancePeriod::fromMonth('2026-05'), null);
        $this->assertSame("PK\x03\x04", substr($bytes, 0, 4));

        $tmp = tempnam(sys_get_temp_dir(), 'ca_layout_test_').'.xlsx';
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);

        $this->assertNotFalse($sheetXml);
        foreach (['CA encaissé', 'Mai 2026', 'Numéro', 'Désignation', 'Montant encaissé', 'CA encaissé du mois'] as $expected) {
            $this->assertStringContainsString($expected, $sheetXml);
        }
    }

    public function test_display_polish_never_changes_the_underlying_ca_encaisse_amounts(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '3456.7800');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-05-29']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '3456.7800', ['payment_date' => '2026-05-30']);

        $period = FinancePeriod::fromMonth('2026-05');
        $service = app(FinanceCaEncaisseService::class);

        $this->assertSame(0, Decimal::compare($service->total($organization, $period, null), '3456.7800'));
        $row = $service->rows($organization, $period, null)->items()[0];
        $this->assertSame(0, Decimal::compare($row['amount'], '3456.7800'));
        $this->assertSame('Paiement comptant', $row['status_label']);

        // The exports build successfully from these same unchanged figures.
        $xlsxBytes = app(FinanceCaEncaisseExcelExport::class)->build($organization, $period, null);
        $this->assertSame("PK\x03\x04", substr($xlsxBytes, 0, 4));
        $pdfResult = app(FinanceCaEncaissePdfExport::class)->build($organization, [$period], null);
        $this->assertSame('%PDF', substr($pdfResult['bytes'], 0, 4));
    }

    /**
     * Swaps in a mock PdfGenerator and returns a plain object whose
     * `->options` is filled in with whatever the controller/export actually
     * passed to `generate()` once the request runs — an object rather than
     * an array so the closure's mutation is visible to the caller (PHP
     * objects are always shared by handle; a plain array captured with
     * `use (&$box)` would still only be a snapshot at return time here).
     */
    private function captureGeneratorOptions(): object
    {
        $box = new \stdClass;
        $box->options = [];
        $generator = Mockery::mock(PdfGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (string $html, array $options) use ($box) {
                $box->options = $options;

                return '%PDF-1.4 fake';
            });
        $this->app->instance(PdfGenerator::class, $generator);

        return $box;
    }
}
