<?php

namespace Tests\Feature\Finance;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\Export\FinanceCaEncaisseExcelExport;
use App\Services\Finance\FinanceCaEncaisseService;
use App\Services\Finance\FinancePeriod;
use App\Support\Decimal;
use Tests\Support\DocumentTestCase;
use ZipArchive;

/**
 * Excel merge-cells fix: one commercial transaction (payment/order group)
 * occupies N physical rows (N = number of sold lines), with every
 * TRANSACTION-level column (Numéro, dates, N° facture/commande, Client,
 * Mode, Montant encaissé, Statut) merged vertically across those rows via
 * OpenSpout's native `Options::mergeCells()` — never simulated with repeated
 * or blank values, and never manual XLSX/XML manipulation. ITEM-level
 * columns (Qté, Référence, Désignation) are never merged.
 */
class FinanceCaEncaisseExcelMergeTest extends DocumentTestCase
{
    /** @return array{User, Organization, Store, SalesOrder} */
    private function multiLineOrder(int $lineCount, string $unitPrice = '1000.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-09-01']);

        for ($i = 1; $i <= $lineCount; $i++) {
            $this->addCustomLine($owner, $order, [
                'description' => "Article {$i}",
                'reference' => "REF-{$i}",
                'quantity' => (string) $i.'.0000',
                'unit_price_excl_tax' => $unitPrice,
            ]);
        }

        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();

        return [$owner, $organization, $store, $order];
    }

    private function sheetXmlOf(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ca_merge_test_').'.xlsx';
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);

        return $xml === false ? '' : $xml;
    }

    public function test_a_single_item_transaction_is_never_merged(): void
    {
        [$owner, $organization, , $order] = $this->multiLineOrder(1, '250.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '250.0000', ['payment_date' => '2026-09-05']);

        $bytes = app(FinanceCaEncaisseExcelExport::class)->build($organization, FinancePeriod::fromMonth('2026-09'), null);
        $xml = $this->sheetXmlOf($bytes);

        $this->assertStringNotContainsString('<mergeCell', $xml, 'a single-item transaction must never be merged');
        $this->assertStringContainsString('Article 1', $xml);
    }

    public function test_a_multi_item_transaction_merges_every_transaction_level_column_across_its_rows(): void
    {
        [$owner, $organization, , $order] = $this->multiLineOrder(3, '1000.0000');
        $orderTotal = $order->fresh()->total_incl_tax;
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, $orderTotal, ['payment_date' => '2026-09-05']);

        $bytes = app(FinanceCaEncaisseExcelExport::class)->build($organization, FinancePeriod::fromMonth('2026-09'), null);
        $xml = $this->sheetXmlOf($bytes);

        // First data row is row 7 (5 metadata rows + 1 blank + the header) —
        // see FinanceCaEncaisseExcelExport::FIRST_DATA_ROW. Three items occupy
        // rows 7-9. Columns: A=Numéro, B=Date de vente, C=Date de paiement,
        // D=N° facture/commande, H=Client, I=Mode, J=Montant encaissé, K=Statut.
        foreach (['A', 'B', 'C', 'D', 'H', 'I', 'J', 'K'] as $column) {
            $this->assertStringContainsString(
                "<mergeCell ref=\"{$column}7:{$column}9\"/>",
                $xml,
                "expected column {$column} to be merged across rows 7-9",
            );
        }
    }

    public function test_item_columns_are_never_merged_even_with_many_lines(): void
    {
        [$owner, $organization, , $order] = $this->multiLineOrder(6, '500.0000');
        $orderTotal = $order->fresh()->total_incl_tax;
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, $orderTotal, ['payment_date' => '2026-09-05']);

        $bytes = app(FinanceCaEncaisseExcelExport::class)->build($organization, FinancePeriod::fromMonth('2026-09'), null);
        $xml = $this->sheetXmlOf($bytes);

        // E=Qté, F=Référence, G=Désignation — item-level, never merged.
        foreach (['E', 'F', 'G'] as $column) {
            $this->assertStringNotContainsString("<mergeCell ref=\"{$column}", $xml, "item column {$column} must never be merged");
        }
        for ($i = 1; $i <= 6; $i++) {
            $this->assertStringContainsString("Article {$i}", $xml);
            $this->assertStringContainsString("REF-{$i}", $xml);
        }
        $this->assertStringNotContainsString('autre', $xml);
    }

    public function test_the_financial_value_appears_only_once_per_transaction_even_when_merged(): void
    {
        [$owner, $organization, , $order] = $this->multiLineOrder(5, '2000.0000');
        $orderTotal = $order->fresh()->total_incl_tax; // 5 x 2000 HT (+ tax if any) — a single payment covers it all.
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, $orderTotal, ['payment_date' => '2026-09-05']);

        $bytes = app(FinanceCaEncaisseExcelExport::class)->build($organization, FinancePeriod::fromMonth('2026-09'), null);
        $xml = $this->sheetXmlOf($bytes);

        // This is the ONLY payment this month, so the footer total equals the
        // same figure — it may legitimately appear a SECOND time there, but
        // never a third (which a "repeat the amount on every item row" bug
        // would produce for a 5-line transaction).
        $formatted = number_format((float) $orderTotal, 2, '.', '');
        $this->assertLessThanOrEqual(2, substr_count($xml, $formatted));
    }

    public function test_two_separate_payments_on_the_same_order_are_never_merged_into_one_block(): void
    {
        [$owner, $organization, , $order] = $this->multiLineOrder(3, '1000.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        // Two distinct posted payments within the same month, same order.
        $this->recordPayment($owner, $order, $account, '2000.0000', ['payment_date' => '2026-09-05']);
        $this->recordPayment($owner, $order, $account, '1000.0000', ['payment_date' => '2026-09-10']);

        $bytes = app(FinanceCaEncaisseExcelExport::class)->build($organization, FinancePeriod::fromMonth('2026-09'), null);
        $xml = $this->sheetXmlOf($bytes);

        // Each payment keeps its own 3-row block (rows 7-9 and 10-12) —
        // never collapsed into a single A7:A12 merge just because both
        // belong to the same order/invoice (§10).
        $this->assertStringContainsString('<mergeCell ref="A7:A9"/>', $xml);
        $this->assertStringContainsString('<mergeCell ref="A10:A12"/>', $xml);
        $this->assertStringNotContainsString('<mergeCell ref="A7:A12"/>', $xml);
    }

    public function test_excel_build_never_mutates_the_underlying_ca_encaisse_total(): void
    {
        [$owner, $organization, , $order] = $this->multiLineOrder(4, '750.0000');
        $orderTotal = $order->fresh()->total_incl_tax;
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, $orderTotal, ['payment_date' => '2026-09-05']);

        $period = FinancePeriod::fromMonth('2026-09');
        $before = app(FinanceCaEncaisseService::class)->total($organization, $period, null);

        app(FinanceCaEncaisseExcelExport::class)->build($organization, $period, null);

        $after = app(FinanceCaEncaisseService::class)->total($organization, $period, null);
        $this->assertSame(0, Decimal::compare($before, $after));
        $this->assertSame(0, Decimal::compare($after, $orderTotal));
    }
}
