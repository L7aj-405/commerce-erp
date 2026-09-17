<?php

namespace Tests\Feature\Finance;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Contracts\PdfGenerator;
use App\Models\User;
use App\Services\Finance\Export\FinanceCaEncaisseExcelExport;
use App\Services\Finance\Export\FinanceCaEncaissePdfExport;
use App\Services\Finance\FinanceCaEncaisseService;
use App\Services\Finance\FinanceJournalService;
use App\Services\Finance\FinancePeriod;
use App\Support\Decimal;
use Mockery;
use Tests\Support\DocumentTestCase;
use ZipArchive;

/**
 * Finance Journal / CA designation fix: a sale/invoice with several sold
 * lines must expose ALL of them everywhere (UI, Excel, PDF) — never a
 * truncated "Article A (+3 autres)" summary (the previous behavior of
 * FinanceCaEncaisseService::designationsBySalesOrder() and
 * FinanceJournalService::designation()). Line expansion is a presentation
 * concern only: it must never change the payment/invoice-grain financial
 * aggregates (CA encaissé total, invoice total_incl_tax).
 */
class FinanceLineDetailTest extends DocumentTestCase
{
    private function fakePdfGenerator(): object
    {
        $box = new \stdClass;
        $box->html = '';
        $generator = Mockery::mock(PdfGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (string $html) use ($box) {
                $box->html = $html;

                return '%PDF-1.4 fake';
            });
        $this->app->instance(PdfGenerator::class, $generator);

        return $box;
    }

    /** @return array{User, \App\Models\Organization, \App\Models\Store, \App\Models\SalesOrder} six-line order fixture */
    private function sixLineOrderFixture(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $taxRate = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-09-01']);

        $boseSpeaker = $this->createProduct($organization, 'BOSE DESIGNMAX DM5SE ENCEINTE MURALE', 'BOSE-DM5SE', [
            'default_sale_price' => '1000.0000', 'tax_rate_id' => $taxRate->getKey(), 'reference' => 'REF-DM5SE', 'label' => 'Blanc',
        ]);
        $this->addCatalogLine($owner, $order, $boseSpeaker->variants->first(), $warehouse, ['quantity' => '3.0000']);

        $boseCeiling = $this->createProduct($organization, 'BOSE DESIGNMAX DM2 CLP ENCEINTE PLAFONNIER', 'BOSE-DM2', [
            'default_sale_price' => '800.0000', 'tax_rate_id' => $taxRate->getKey(), 'reference' => 'REF-DM2',
        ]);
        $this->addCatalogLine($owner, $order, $boseCeiling->variants->first(), $warehouse, ['quantity' => '1.0000']);

        $amplifier = $this->createProduct($organization, 'BOSE AMPLIFICATEUR P2600A', 'BOSE-P2600A', [
            'default_sale_price' => '5000.0000', 'tax_rate_id' => $taxRate->getKey(), 'reference' => null,
        ]);
        $this->addCatalogLine($owner, $order, $amplifier->variants->first(), $warehouse, ['quantity' => '1.0000']);

        $wiim = $this->createProduct($organization, 'WIIM PRO+', 'WIIM-PRO', [
            'default_sale_price' => '900.0000', 'tax_rate_id' => $taxRate->getKey(), 'reference' => 'REF-WIIM',
        ]);
        $this->addCatalogLine($owner, $order, $wiim->variants->first(), $warehouse, ['quantity' => '2.0000']);

        $mackie = $this->createProduct($organization, 'TABLE DE MIXAGE MACKIE PRO FX16V3', 'MACKIE-FX16', [
            'default_sale_price' => '3500.0000', 'tax_rate_id' => $taxRate->getKey(), 'reference' => 'REF-MACKIE',
        ]);
        $this->addCatalogLine($owner, $order, $mackie->variants->first(), $warehouse, ['quantity' => '1.0000']);

        $ddj = $this->createProduct($organization, 'DDJ RX3 PIONEER', 'PIONEER-RX3', [
            'default_sale_price' => '6000.0000', 'tax_rate_id' => $taxRate->getKey(), 'reference' => 'REF-DDJ',
        ]);
        $this->addCatalogLine($owner, $order, $ddj->variants->first(), $warehouse, ['quantity' => '1.0000']);

        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();

        return [$owner, $organization, $store, $order];
    }

    public function test_ca_encaisse_exposes_every_sold_line_never_a_truncated_summary(): void
    {
        [$owner, $organization, , $order] = $this->sixLineOrderFixture();
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, $order->fresh()->total_incl_tax, ['payment_date' => '2026-09-05']);

        $caEncaisse = app(FinanceCaEncaisseService::class);
        $row = $caEncaisse->rows($organization, FinancePeriod::fromMonth('2026-09'), null)->items()[0];

        $this->assertCount(6, $row['lines'], 'all six sold lines must be exposed');
        $designations = collect($row['lines'])->pluck('designation')->all();
        foreach (['DM5SE', 'DM2 CLP', 'P2600A', 'WIIM PRO+', 'MACKIE', 'DDJ RX3'] as $needle) {
            $this->assertTrue(collect($designations)->contains(fn ($d) => str_contains($d, $needle)), "missing line: {$needle}");
        }
        // The previous defect's exact signature must never resurface.
        $encoded = json_encode($row['lines']);
        $this->assertStringNotContainsString('autre', $encoded);
        $this->assertStringNotContainsString('+5', $encoded);
    }

    public function test_quantity_reference_and_variant_are_preserved_per_line(): void
    {
        [$owner, $organization, , $order] = $this->sixLineOrderFixture();
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, $order->fresh()->total_incl_tax, ['payment_date' => '2026-09-05']);

        $row = app(FinanceCaEncaisseService::class)->rows($organization, FinancePeriod::fromMonth('2026-09'), null)->items()[0];
        $speaker = collect($row['lines'])->firstOrFail(fn ($l) => str_contains($l['designation'], 'DM5SE'));

        $this->assertSame(0, Decimal::compare($speaker['quantity'], '3.0000'));
        $this->assertSame('REF-DM5SE', $speaker['reference']);
        $this->assertSame('Blanc', $speaker['variant']);

        // No explicit `reference` on this product — falls back to the SKU.
        $amplifier = collect($row['lines'])->firstOrFail(fn ($l) => str_contains($l['designation'], 'P2600A'));
        $this->assertSame('BOSE-P2600A', $amplifier['reference']);
    }

    public function test_a_single_item_invoice_still_returns_exactly_one_line(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '250.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '250.0000', ['payment_date' => '2026-09-05']);

        $row = app(FinanceCaEncaisseService::class)->rows($organization, FinancePeriod::fromMonth('2026-09'), null)->items()[0];

        $this->assertCount(1, $row['lines']);
    }

    public function test_expanding_lines_never_multiplies_the_ca_encaisse_total(): void
    {
        [$owner, $organization, , $order] = $this->sixLineOrderFixture();
        $invoiceTotal = $order->fresh()->total_incl_tax;
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, $invoiceTotal, ['payment_date' => '2026-09-05']);

        $caEncaisse = app(FinanceCaEncaisseService::class);
        $period = FinancePeriod::fromMonth('2026-09');

        // The total is the actual amount collected — NOT multiplied by the
        // six sold lines the underlying order happens to have.
        $this->assertSame(0, Decimal::compare($caEncaisse->total($organization, $period, null), $invoiceTotal));
        $row = $caEncaisse->rows($organization, $period, null)->items()[0];
        $this->assertSame(0, Decimal::compare($row['amount'], $invoiceTotal));
        $this->assertCount(6, $row['lines']);
    }

    public function test_partial_payment_with_multiple_items_shows_all_lines_and_only_the_collected_amount(): void
    {
        [$owner, $organization, , $order] = $this->sixLineOrderFixture();
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        // Only a fraction of the six-line invoice's total is collected in September.
        $this->recordPayment($owner, $order, $account, '3000.0000', ['payment_date' => '2026-09-15']);

        $row = app(FinanceCaEncaisseService::class)->rows($organization, FinancePeriod::fromMonth('2026-09'), null)->items()[0];

        $this->assertSame(0, Decimal::compare($row['amount'], '3000.0000'), 'CA encaissé must show only what was actually collected');
        $this->assertCount(6, $row['lines'], 'the full article detail must still be visible for a partial payment');
    }

    public function test_advance_payment_before_invoice_exposes_all_order_lines_without_a_fake_invoice(): void
    {
        [$owner, $organization, , $order] = $this->sixLineOrderFixture();
        $account = $this->createFinancialAccount($organization);
        // Deliberately: no invoice at all yet.
        $this->recordPayment($owner, $order, $account, $order->fresh()->total_incl_tax, ['payment_date' => '2026-09-05']);

        $row = app(FinanceCaEncaisseService::class)->rows($organization, FinancePeriod::fromMonth('2026-09'), null)->items()[0];

        $this->assertSame('Avance sur commande', $row['status_label']);
        $this->assertNull($row['invoice_id']);
        $this->assertSame('order', $row['reference_type']);
        $this->assertCount(6, $row['lines'], 'order lines must be shown even with no invoice yet');
    }

    public function test_journal_exposes_every_line_while_the_invoice_total_stays_at_the_invoice_grain(): void
    {
        [$owner, $organization, , $order] = $this->sixLineOrderFixture();
        $invoiceTotal = $order->fresh()->total_incl_tax;
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-09-10']));

        $row = app(FinanceJournalService::class)->rows($organization, FinancePeriod::fromMonth('2026-09'), null)->items()[0];

        $this->assertSame($invoice->invoice_number, $row['invoice_number']);
        $this->assertCount(6, $row['lines']);
        $this->assertSame(0, Decimal::compare($row['total_incl_tax'], $invoiceTotal));
        $this->assertStringNotContainsString('autre', json_encode($row['lines']));
    }

    public function test_excel_export_contains_every_line_and_the_collected_amount_only_once(): void
    {
        [$owner, $organization, , $order] = $this->sixLineOrderFixture();
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, $order->fresh()->total_incl_tax, ['payment_date' => '2026-09-05']);

        $bytes = app(FinanceCaEncaisseExcelExport::class)->build($organization, FinancePeriod::fromMonth('2026-09'), null);
        $this->assertSame("PK\x03\x04", substr($bytes, 0, 4));

        $tmp = tempnam(sys_get_temp_dir(), 'ca_lines_test_').'.xlsx';
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);

        $this->assertNotFalse($sheetXml);
        foreach (['DM5SE', 'DM2 CLP', 'P2600A', 'WIIM PRO+', 'MACKIE', 'DDJ RX3'] as $needle) {
            $this->assertStringContainsString($needle, $sheetXml, "missing line in xlsx: {$needle}");
        }
        $this->assertStringNotContainsString('autre', $sheetXml);
        foreach (['REF-DM5SE', 'REF-DM2', 'REF-WIIM', 'REF-MACKIE', 'REF-DDJ', 'BOSE-P2600A'] as $reference) {
            $this->assertStringContainsString($reference, $sheetXml, "missing reference in xlsx: {$reference}");
        }

        // The one qualifying payment's amount appears at most twice in the
        // whole sheet: once on the first line row of its group, once in the
        // "CA encaissé du mois" footer (this is the ONLY payment this
        // month, so footer total == that same amount) — never a third time,
        // which is what a naive "repeat on every line" bug would produce.
        $formatted = number_format((float) $order->fresh()->total_incl_tax, 2, '.', '');
        $this->assertLessThanOrEqual(2, substr_count($sheetXml, $formatted));
    }

    public function test_pdf_export_receives_every_line(): void
    {
        $captured = $this->fakePdfGenerator();
        [$owner, $organization, , $order] = $this->sixLineOrderFixture();
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, $order->fresh()->total_incl_tax, ['payment_date' => '2026-09-05']);

        app(FinanceCaEncaissePdfExport::class)->build($organization, [FinancePeriod::fromMonth('2026-09')], null);

        foreach (['DM5SE', 'DM2 CLP', 'P2600A', 'WIIM PRO', 'MACKIE', 'DDJ RX3'] as $needle) {
            $this->assertStringContainsString($needle, $captured->html, "missing line in pdf html: {$needle}");
        }
        $this->assertStringNotContainsString('autre', $captured->html);
    }

    public function test_lines_from_another_organization_never_leak_into_this_ones_rows(): void
    {
        [$ownerA, $organizationA, , $orderA] = $this->documentFixture(total: '100.0000');
        $this->issueInvoice($ownerA, $this->createInvoice($ownerA, $orderA));
        $accountA = $this->createFinancialAccount($organizationA);
        $this->recordPayment($ownerA, $orderA, $accountA, '100.0000', ['payment_date' => '2026-09-05']);

        [$ownerB, $organizationB, , $orderB] = $this->sixLineOrderFixture();
        $this->issueInvoice($ownerB, $this->createInvoice($ownerB, $orderB));
        $accountB = $this->createFinancialAccount($organizationB);
        $this->recordPayment($ownerB, $orderB, $accountB, $orderB->fresh()->total_incl_tax, ['payment_date' => '2026-09-06']);

        $period = FinancePeriod::fromMonth('2026-09');
        $rowsA = app(FinanceCaEncaisseService::class)->rows($organizationA, $period, null)->items();

        $this->assertCount(1, $rowsA);
        $this->assertCount(1, $rowsA[0]['lines']);
        $this->assertStringNotContainsString('DM5SE', json_encode($rowsA[0]['lines']));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
