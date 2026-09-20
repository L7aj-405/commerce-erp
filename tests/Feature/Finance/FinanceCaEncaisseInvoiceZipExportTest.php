<?php

namespace Tests\Feature\Finance;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Actions\Sales\StartSalesOrderCorrectionAction;
use App\Contracts\PdfGenerator;
use App\Models\DocumentStampApposition;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Finance\Export\FinanceCaEncaisseInvoiceZipExport;
use App\Services\Finance\FinanceInvoiceReadModel;
use App\Services\Finance\FinancePeriod;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\QuotationTestCase;
use ZipArchive;

/**
 * "Exporter les factures (.ZIP)" for CA encaissé. The PdfGenerator is mocked
 * to a tiny fake payload in every test here (matching
 * FinanceCaEncaissePdfLayoutTest's own convention) — this suite is about the
 * ZIP's invoice SELECTION, deduplication, filenames, manifest and
 * read-only-ness, never about Dompdf's actual rendering (already covered
 * elsewhere).
 */
class FinanceCaEncaisseInvoiceZipExportTest extends QuotationTestCase
{
    private function fakePdfGenerator(): void
    {
        $generator = Mockery::mock(PdfGenerator::class);
        $generator->shouldReceive('generate')->andReturn('%PDF-1.4 fake');
        $this->app->instance(PdfGenerator::class, $generator);
    }

    /** @return array{ZipArchive, string} */
    private function openZip(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);

        return [$zip, $path];
    }

    private function safeNumber(string $number): string
    {
        return trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', $number), '-') ?: 'document';
    }

    public function test_zip_contains_the_official_invoice_pdf_named_after_its_invoice_number(): void
    {
        $this->fakePdfGenerator();
        [$owner, $organization, , $order] = $this->documentFixture(total: '10000.0000');
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '10000.0000', ['payment_date' => '2026-09-15']);

        $result = app(FinanceCaEncaisseInvoiceZipExport::class)->build($organization, FinancePeriod::fromMonth('2026-09'), null);

        $this->assertStringEndsWith('Factures_2026-09.zip', $result['filename']);
        [$zip] = $this->openZip($result['path']);
        $safeNumber = $this->safeNumber($invoice->invoice_number);
        $expectedEntry = "Factures/{$safeNumber}/Facture-{$safeNumber}.pdf";
        $this->assertNotFalse($zip->locateName($expectedEntry), 'expected entry '.$expectedEntry);
        $this->assertSame('%PDF-1.4 fake', $zip->getFromName($expectedEntry));
        $zip->close();
        @unlink($result['path']);
    }

    public function test_an_invoice_paid_in_two_installments_within_the_period_appears_only_once(): void
    {
        $this->fakePdfGenerator();
        [$owner, $organization, , $order] = $this->documentFixture(total: '1500.0000');
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '1000.0000', ['payment_date' => '2026-09-05']);
        $this->recordPayment($owner, $order, $account, '500.0000', ['payment_date' => '2026-09-20']);

        $result = app(FinanceCaEncaisseInvoiceZipExport::class)->build($organization, FinancePeriod::fromMonth('2026-09'), null);

        [$zip] = $this->openZip($result['path']);
        // manifest.csv + exactly one invoice entry, never two.
        $this->assertSame(2, $zip->numFiles);
        $safeNumber = $this->safeNumber($invoice->invoice_number);
        $this->assertNotFalse($zip->locateName("Factures/{$safeNumber}/Facture-{$safeNumber}.pdf"));
        $zip->close();
        @unlink($result['path']);
    }

    public function test_corrected_invoice_zip_filename_and_manifest_are_version_aware(): void
    {
        $this->fakePdfGenerator();
        [$owner, $organization, , $order] = $this->documentFixture(total: '100.0000');
        $original = $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-09-01']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '100.0000', ['payment_date' => '2026-09-05']);

        app(StartSalesOrderCorrectionAction::class)->execute($owner, $order, 'Ajout commercial');
        $line = $order->fresh()->lines()->firstOrFail();
        app(SaveSalesOrderLineAction::class)->execute($owner, $order->fresh(), [
            'line_type' => 'custom', 'description' => 'Consulting', 'reference' => null, 'unit_label' => 'hour',
            'quantity' => '2.0000', 'unit_price_excl_tax' => '100.0000', 'tax_rate_id' => null,
            'discount_type' => 'none', 'discount_value' => '0.0000',
        ], $line);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh())->fresh();
        $current = $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-09-02']));

        $result = app(FinanceCaEncaisseInvoiceZipExport::class)->build($organization, FinancePeriod::fromMonth('2026-09'), null);
        [$zip] = $this->openZip($result['path']);
        $safeNumber = $this->safeNumber($original->invoice_number);
        $entry = "Factures/{$safeNumber}/Facture-{$safeNumber}-V2.pdf";
        $manifest = $zip->getFromName('manifest.csv');

        $this->assertSame($original->invoice_number, $current->invoice_number);
        $this->assertNotFalse($zip->locateName($entry));
        $this->assertNotFalse($manifest);
        $this->assertStringContainsString('Version', $manifest);
        $this->assertStringContainsString(';2;', $manifest);
        $zip->close();
        @unlink($result['path']);
    }

    public function test_a_partial_payment_still_ships_the_full_official_invoice_not_a_partial_one(): void
    {
        $this->fakePdfGenerator();
        [$owner, $organization, , $order] = $this->documentFixture(total: '10000.0000');
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        // Only 3,000 of the 10,000 collected in September.
        $this->recordPayment($owner, $order, $account, '3000.0000', ['payment_date' => '2026-09-15']);

        $result = app(FinanceCaEncaisseInvoiceZipExport::class)->build($organization, FinancePeriod::fromMonth('2026-09'), null);

        [$zip] = $this->openZip($result['path']);
        $manifest = $zip->getFromName('manifest.csv');
        $zip->close();
        @unlink($result['path']);

        $this->assertNotFalse($manifest);
        // The manifest exposes the payment-period amount (3,000) NEXT TO the
        // invoice's real, unmodified total (10,000) — never a fabricated
        // "3,000 invoice".
        $this->assertStringContainsString($invoice->invoice_number, $manifest);
        $this->assertStringContainsString('10000.0000', $manifest);
        $this->assertStringContainsString('3000.0000', $manifest);
    }

    public function test_payment_before_invoice_produces_no_invoice_in_the_zip(): void
    {
        [$owner, $organization, $store] = $this->documentFixture();
        $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-09-01']);
        $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '2000.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '2000.0000', ['payment_date' => '2026-09-03']);
        // Deliberately: no invoice at all for this advance.

        $this->expectException(HttpException::class);
        try {
            app(FinanceCaEncaisseInvoiceZipExport::class)->build($organization, FinancePeriod::fromMonth('2026-09'), null);
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('Aucune facture émise', $exception->getMessage());
            throw $exception;
        }
    }

    public function test_a_draft_invoice_is_never_exported_as_an_official_document(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '900.0000');
        // Created but never issued — stays Draft.
        $this->createInvoice($owner, $order);
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '900.0000', ['payment_date' => '2026-09-10']);

        try {
            app(FinanceCaEncaisseInvoiceZipExport::class)->build($organization, FinancePeriod::fromMonth('2026-09'), null);
            $this->fail('Expected the export to find no eligible (Issued) invoice.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_a_stamped_invoice_reuses_its_frozen_apposition_and_an_unstamped_one_stays_unstamped(): void
    {
        $this->fakePdfGenerator();
        Storage::fake('local');
        [$owner, $organization, , $orderStamped] = $this->documentFixture(total: '400.0000');
        $this->configureOrganizationStamp($organization);
        $stampedInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $orderStamped));
        $this->actingAs($owner)->post(route('invoices.stamp', $stampedInvoice))->assertRedirect();

        $order2 = $this->createDraftOrder($owner, $organization, $orderStamped->store, null, ['sale_date' => '2026-09-02']);
        $this->addCustomLine($owner, $order2, ['unit_price_excl_tax' => '250.0000']);
        $order2 = app(ConfirmSalesOrderAction::class)->execute($owner, $order2)->fresh();
        $unstampedInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order2));

        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $orderStamped->fresh(), $account, '400.0000', ['payment_date' => '2026-09-05']);
        $this->recordPayment($owner, $order2, $account, '250.0000', ['payment_date' => '2026-09-06']);

        $this->assertNotNull($stampedInvoice->fresh()->stampApposition);

        $result = app(FinanceCaEncaisseInvoiceZipExport::class)->build($organization, FinancePeriod::fromMonth('2026-09'), null);

        [$zip] = $this->openZip($result['path']);
        $manifest = $zip->getFromName('manifest.csv');
        $zip->close();
        @unlink($result['path']);

        $this->assertNotFalse($manifest);
        $lines = array_filter(array_map('trim', explode("\n", $manifest)));
        $stampedLine = current(array_filter($lines, fn ($l) => str_contains($l, $stampedInvoice->invoice_number)));
        $unstampedLine = current(array_filter($lines, fn ($l) => str_contains($l, $unstampedInvoice->invoice_number)));
        $this->assertStringContainsString('Cachetée', $stampedLine);
        $this->assertStringContainsString('Non cachetée', $unstampedLine);
    }

    public function test_export_never_mutates_the_invoice_or_creates_a_stamp_apposition(): void
    {
        $this->fakePdfGenerator();
        [$owner, $organization, , $order] = $this->documentFixture(total: '600.0000');
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '600.0000', ['payment_date' => '2026-09-08']);
        $beforeUpdatedAt = $invoice->fresh()->updated_at;
        $beforeStatus = $invoice->fresh()->status;
        $beforeStampCount = DocumentStampApposition::query()->count();

        $result = app(FinanceCaEncaisseInvoiceZipExport::class)->build($organization, FinancePeriod::fromMonth('2026-09'), null);
        @unlink($result['path']);

        $invoice->refresh();
        $this->assertTrue($invoice->updated_at->equalTo($beforeUpdatedAt));
        $this->assertSame($beforeStatus, $invoice->status);
        $this->assertSame($beforeStampCount, DocumentStampApposition::query()->count());
    }

    public function test_more_than_the_export_limit_is_rejected_with_a_clear_french_message(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        // 501 unsaved, minimal stand-ins — proving the LIMIT guard fires
        // before any per-invoice work, without paying for 501 real fixtures.
        $stubs = collect(range(1, 501))->map(function (int $i) use ($organization) {
            $invoice = new Invoice;

            return $invoice->forceFill(['id' => $i, 'organization_id' => $organization->getKey(), 'sales_order_id' => $i]);
        });

        $readModel = Mockery::mock(FinanceInvoiceReadModel::class);
        $readModel->shouldReceive('receivedPaymentDuringMonth')->once()->andReturn($stubs);
        $this->app->instance(FinanceInvoiceReadModel::class, $readModel);

        try {
            app(FinanceCaEncaisseInvoiceZipExport::class)->build($organization, FinancePeriod::fromMonth('2026-09'), null);
            $this->fail('Expected the oversized-selection guard to abort.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('501 factures', $exception->getMessage());
        }
    }

    public function test_export_requires_finance_export_permission_over_http(): void
    {
        $this->fakePdfGenerator();
        [$owner, $organization, , $order] = $this->documentFixture(total: '300.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '300.0000', ['payment_date' => '2026-09-05']);

        $viewer = User::factory()->create();
        $this->addOrganizationMember($organization, $viewer, ['finance.view']);
        $this->activate($viewer, $organization);

        $this->actingAs($viewer)
            ->get(route('finance.ca-encaisse.export.invoices-zip', ['month' => '2026-09']))
            ->assertForbidden();

        $exporter = User::factory()->create();
        $this->addOrganizationMember($organization, $exporter, ['finance.view', 'finance.export']);
        $this->activate($exporter, $organization);

        $this->actingAs($exporter)
            ->get(route('finance.ca-encaisse.export.invoices-zip', ['month' => '2026-09']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');
    }

    public function test_a_store_id_from_another_organization_is_rejected_not_leaked(): void
    {
        $this->fakePdfGenerator();
        [$ownerA, $organizationA, , $orderA] = $this->documentFixture(total: '300.0000');
        $this->issueInvoice($ownerA, $this->createInvoice($ownerA, $orderA));
        $accountA = $this->createFinancialAccount($organizationA);
        $this->recordPayment($ownerA, $orderA, $accountA, '300.0000', ['payment_date' => '2026-09-05']);

        $ownerB = User::factory()->create();
        $organizationB = $this->createOrganization($ownerB);
        $storeB = $this->createStore($organizationB, $ownerB);

        $this->activate($ownerA, $organizationA);

        $this->actingAs($ownerA)
            ->get(route('finance.ca-encaisse.export.invoices-zip', ['month' => '2026-09', 'store_id' => $storeB->id]))
            ->assertNotFound();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
