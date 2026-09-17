<?php

namespace App\Services\Finance\Export;

use App\Models\Organization;
use App\Models\Store;
use App\Services\Finance\FinanceCaEncaisseService;
use App\Services\Finance\FinancePeriod;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * CA encaissé workbook: one sheet, one row per SOLD LINE (never one row per
 * payment with a truncated "Article A (+3 autres)" summary — see the Finance
 * Journal/CA designation-fix audit). Columns: Numéro / Date de vente / Date
 * de paiement / N° facture-commande / Qté / Référence / Désignation / Client
 * / Mode de paiement / Montant encaissé / Statut, plus a visually separated
 * monthly total row. Same OpenSpout pipeline as FinanceSituationExcelExport
 * — streamed to a temp file, never buffering rows in a spreadsheet-library
 * object graph, so an arbitrarily large month never blows up memory.
 *
 * Visual grouping (the merge-cells fix): a payment/order's TRANSACTION-level
 * columns (Numéro, both dates, N° facture/commande, Client, Mode, Montant
 * encaissé, Statut) are written ONLY on the group's first line row and
 * merged, via OpenSpout's native `Options::mergeCells()` (0-indexed columns,
 * 1-indexed rows — never manual XLSX/XML manipulation), across every row the
 * group occupies — a traditional accounting-journal look, one physical
 * Excel row per sold ITEM but one logical block per transaction. A
 * single-item group is never merged (nothing to merge: N=1 is already a
 * normal row). ITEM-level columns (Qté, Référence, Désignation) are never
 * merged and keep one value per row.
 *
 * The MONETARY "Montant encaissé" column is the one column where merging is
 * also a financial-integrity requirement, not just cosmetics: writing it on
 * every line row (instead of once, merged) would make a naive `=SUM()` over
 * the column silently multiply CA encaissé by the line count — exactly the
 * double-counting the spec forbids. The monthly total below is always
 * computed independently from FinanceCaEncaisseService::total() (a single
 * payment-grain SUM), never by re-summing this sheet's cells.
 *
 * Borders: every item row keeps a light bottom border across its own
 * columns; the LAST row of each transaction (whichever row that is — the
 * only row for a single-item group, or the bottom of the merge otherwise)
 * gets a stronger separator border spanning every column instead, so
 * consecutive transactions are always visually closed off and the border
 * never breaks discontinuously around a merged region (merged cells inherit
 * their edge borders from the cell actually occupying that edge position —
 * middle rows of a merge carry no border at all, since Excel hides their
 * content and edges anyway).
 *
 * Filter/sort note (documented limitation, §14): once a transaction's
 * identifying columns are merged, Excel's AutoFilter/sort on those columns
 * only "sees" a value on the merged range's top-left row — the item rows
 * beneath read as blank for that column. Qté/Référence/Désignation remain
 * fully filterable/sortable on every row since they are never merged.
 *
 * No logo: the installed openspout/openspout ~4.32 has no image-embedding
 * API at all (confirmed against its source — there is nothing resembling
 * addImage()/Image anywhere in the package). Rather than add a second
 * spreadsheet dependency just for a logo, the workbook leans on a strong
 * text header instead; the PDF export carries the actual logo branding.
 *
 * Dates are written as plain formatted strings ("12/09/2026"), not native
 * Excel date cells: OpenSpout only assigns a display number format when the
 * cell's Style explicitly sets one (there is no automatic date-shaped
 * default for a DateTimeCell), so a bare Carbon value would otherwise
 * render as a raw serial number. A formatted string keeps the exact same
 * "always readable" guarantee as FinanceSituationExcelExport's own date
 * columns, with zero risk of an unstyled numeric date.
 */
class FinanceCaEncaisseExcelExport
{
    private const HEADER_LABELS = [
        'Numéro', 'Date de vente', 'Date de paiement', 'N° facture / commande', 'Qté', 'Référence', 'Désignation',
        'Client', 'Mode de paiement', 'Montant encaissé', 'Statut',
    ];

    /** Excel column widths, in character units, matching HEADER_LABELS order. */
    private const COLUMN_WIDTHS = [14, 13, 13, 16, 7, 14, 36, 24, 16, 16, 20];

    /** 0-indexed position of the "Qté" column. */
    private const QUANTITY_COLUMN_INDEX = 4;

    /** 0-indexed position of the "Référence" column. */
    private const REFERENCE_COLUMN_INDEX = 5;

    /** 0-indexed position of the "Désignation" column. */
    private const DESIGNATION_COLUMN_INDEX = 6;

    /** 0-indexed position of the "Montant encaissé" column. */
    private const AMOUNT_COLUMN_INDEX = 9;

    /** 0-indexed positions of every TRANSACTION-level column — merged across a multi-item group, item columns excluded. */
    private const TRANSACTION_COLUMN_INDEXES = [0, 1, 2, 3, 7, 8, 9, 10];

    /** 1-indexed row the first data row (right after the header) lands on. */
    private const FIRST_DATA_ROW = 7;

    public function __construct(private readonly FinanceCaEncaisseService $caEncaisse) {}

    /** @return string raw XLSX file bytes */
    public function build(Organization $organization, FinancePeriod $period, ?Store $store): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'finance_ca_encaisse_').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($tempPath);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('CA encaissé');
        foreach (self::COLUMN_WIDTHS as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $writer->addRow(Row::fromValues(['CA encaissé — '.$period->label()], $this->titleStyle()));
        $writer->addRow(Row::fromValues(['Organisation', $organization->name]));
        $writer->addRow(Row::fromValues(['Boutique', $store?->name ?? 'Toutes les boutiques']));
        $writer->addRow(Row::fromValues(['Généré le', now()->format('d/m/Y H:i')]));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(self::HEADER_LABELS, $this->headerStyle()));
        // Freeze the 5 metadata rows + blank + the header itself (row 6), so
        // the header stays visible while scrolling a long month.
        $sheet->setSheetView((new SheetView)->setFreezeRow(7));

        $currentRow = self::FIRST_DATA_ROW;
        foreach ($this->caEncaisse->cursor($organization, $period, $store) as $row) {
            // Defensive fallback only — every real order has at least one
            // line; this just guarantees the payment row is never silently
            // dropped if that ever weren't true.
            $lines = $row['lines'] !== [] ? $row['lines'] : [['quantity' => null, 'designation' => '—', 'reference' => null, 'variant' => null]];
            $lastIndex = count($lines) - 1;
            $startRow = $currentRow;

            foreach ($lines as $index => $line) {
                $isFirst = $index === 0;
                $isLast = $index === $lastIndex;
                $designation = $line['designation'].($line['variant'] ? ' — '.$line['variant'] : '');

                $values = [
                    $isFirst ? $row['payment_number'] : '',
                    $isFirst ? Carbon::parse($row['sale_date'])->format('d/m/Y') : '',
                    $isFirst ? Carbon::parse($row['payment_date'])->format('d/m/Y') : '',
                    $isFirst ? $row['reference'] : '',
                    $line['quantity'] !== null ? $this->quantity($line['quantity']) : '',
                    $line['reference'] ?? '',
                    $designation,
                    $isFirst ? $row['customer'] : '',
                    $isFirst ? $row['method_label'] : '',
                    // Written once per payment/order group — see the class doc.
                    $isFirst ? $this->amount($row['amount']) : '',
                    $isFirst ? $row['status_label'] : '',
                ];
                $styles = [
                    0 => $this->transactionCenterStyle($isLast),
                    1 => $this->transactionCenterStyle($isLast),
                    2 => $this->transactionCenterStyle($isLast),
                    3 => $this->transactionCenterStyle($isLast),
                    self::QUANTITY_COLUMN_INDEX => $this->quantityStyle($isLast),
                    self::REFERENCE_COLUMN_INDEX => $this->itemTextStyle($isLast),
                    self::DESIGNATION_COLUMN_INDEX => $this->itemTextStyle($isLast),
                    7 => $this->transactionLeftStyle($isLast),
                    8 => $this->transactionCenterStyle($isLast),
                    self::AMOUNT_COLUMN_INDEX => $this->transactionAmountStyle($isLast),
                    10 => $this->transactionCenterStyle($isLast),
                ];
                $writer->addRow(Row::fromValuesWithStyles($values, null, $styles));
                $currentRow++;
            }

            // §5 — never merge a single-item transaction; N > 1 only.
            if (count($lines) > 1) {
                $endRow = $currentRow - 1;
                foreach (self::TRANSACTION_COLUMN_INDEXES as $column) {
                    $writer->getOptions()->mergeCells($column, $startRow, $column, $endRow);
                }
            }
        }

        $total = $this->caEncaisse->total($organization, $period, $store);
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValuesWithStyles(
            ['CA encaissé du mois', '', '', '', '', '', '', '', '', $this->amount($total), ''],
            $this->totalLabelStyle(),
            [self::AMOUNT_COLUMN_INDEX => $this->totalAmountStyle()],
        ));

        $writer->close();

        $bytes = file_get_contents($tempPath);
        @unlink($tempPath);

        return $bytes === false ? '' : $bytes;
    }

    private function headerStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor('2B3A30')
            ->setCellAlignment(CellAlignment::LEFT);
    }

    private function titleStyle(): Style
    {
        return (new Style)->setFontBold()->setFontSize(13);
    }

    /** Right-aligned, DH-formatted numeric style shared by data and total rows. */
    private function amountStyle(): Style
    {
        return (new Style)
            ->setCellAlignment(CellAlignment::RIGHT)
            ->setFormat('#,##0.00" DH"');
    }

    private function totalAmountStyle(): Style
    {
        return $this->amountStyle()
            ->setFontBold()
            ->setFontSize(11)
            ->setBorder(new Border(new BorderPart(Border::TOP, Color::BLACK, Border::WIDTH_MEDIUM, Border::STYLE_SOLID)));
    }

    private function totalLabelStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontSize(11)
            ->setBorder(new Border(new BorderPart(Border::TOP, Color::BLACK, Border::WIDTH_MEDIUM, Border::STYLE_SOLID)));
    }

    private function amount(?string $value): float
    {
        // Presentation-only: the authoritative DECIMAL(19,4) string is never
        // used for calculation past this point — see
        // FinanceSituationExcelExport::amount() for the same convention.
        return round((float) ($value ?? '0'), 2);
    }

    private function quantity(string $value): float
    {
        // Presentation-only, same convention as amount(): the authoritative
        // DECIMAL(19,4) quantity string is never used for calculation here.
        return (float) $value;
    }

    /**
     * Right-aligned, trimmed-decimal numeric style for the "Qté" column —
     * an ITEM-level column, so it always keeps its own light per-row border
     * (never merged), replaced by the heavier transaction separator on the
     * group's last row.
     */
    private function quantityStyle(bool $closing): Style
    {
        return (new Style)
            ->setCellAlignment(CellAlignment::RIGHT)
            ->setFormat('0.####')
            ->setBorder($closing ? $this->separatorBorder() : $this->lightBorder());
    }

    /** Left-aligned ITEM-level style, shared by Référence and Désignation. */
    private function itemTextStyle(bool $closing): Style
    {
        return (new Style)
            ->setCellAlignment(CellAlignment::LEFT)
            ->setBorder($closing ? $this->separatorBorder() : $this->lightBorder());
    }

    /**
     * TRANSACTION-level style for a short, center-aligned value (Numéro,
     * dates, N° facture/commande, Mode, Statut) — the value itself is only
     * ever written on a group's first row (see build()); Excel displays a
     * merged range using the top-left cell's style/value, so this style is
     * what the accountant actually sees once merged. The separator border is
     * only meaningful on whichever row currently closes the transaction
     * (the merge's bottom edge, or the only row of a single-item group) —
     * a middle row of a multi-item merge carries no border at all, since
     * Excel hides its content and edges once merged over.
     */
    private function transactionCenterStyle(bool $closing): Style
    {
        $style = (new Style)
            ->setCellAlignment(CellAlignment::CENTER)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setShouldWrapText();

        return $closing ? $style->setBorder($this->separatorBorder()) : $style;
    }

    /** TRANSACTION-level style for a longer, left-aligned value (Client). */
    private function transactionLeftStyle(bool $closing): Style
    {
        $style = (new Style)
            ->setCellAlignment(CellAlignment::LEFT)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setShouldWrapText();

        return $closing ? $style->setBorder($this->separatorBorder()) : $style;
    }

    /** TRANSACTION-level, DH-formatted numeric style for "Montant encaissé" once merged/centered vertically. */
    private function transactionAmountStyle(bool $closing): Style
    {
        $style = $this->amountStyle()->setCellVerticalAlignment(CellVerticalAlignment::CENTER);

        return $closing ? $style->setBorder($this->separatorBorder()) : $style;
    }

    /** The heavier rule that closes off one whole transaction from the next — spans every column on that row. */
    private function separatorBorder(): Border
    {
        return new Border(new BorderPart(Border::BOTTOM, '2B3A30', Border::WIDTH_MEDIUM, Border::STYLE_SOLID));
    }

    /** The light rule between two sold lines within the SAME transaction. */
    private function lightBorder(): Border
    {
        return new Border(new BorderPart(Border::BOTTOM, 'E5E9F0', Border::WIDTH_THIN, Border::STYLE_SOLID));
    }
}
