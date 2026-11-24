<?php

namespace App\Services\Finance\Export;

use App\Support\SpreadsheetSafeText;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generic, safe CSV / XLSX writer for Finance clients reports. The rows given to it are the SAME rows the page shows
 * (same service, same filters), so an export always reconciles with its screen. Amounts stay exact decimal strings in PHP:
 * CSV writes the string; XLSX hands the string to NumericCell (the file format stores IEEE doubles, unavoidable).
 * Free text goes through SpreadsheetSafeText (formula-injection safe). Nothing is held as one in-memory workbook.
 */
final class TabularExport
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<list<mixed>>  $rows
     * @param  list<int>  $moneyColumns  zero-based column indexes holding decimal-string amounts
     */
    public static function csv(string $filename, array $headers, iterable $rows, array $moneyColumns = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ';');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => SpreadsheetSafeText::escape($v === null ? '' : (string) $v), $row), ';');
            }
            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store, max-age=0']);
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<list<mixed>>  $rows
     * @param  list<int>  $moneyColumns
     */
    public static function xlsx(string $filename, string $sheetName, array $headers, iterable $rows, array $moneyColumns = []): StreamedResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'fin_export_');
        if ($path === false) {
            throw new RuntimeException('Impossible de préparer le fichier Excel.');
        }
        $writer = new Writer;
        try {
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName(mb_substr($sheetName, 0, 31));
            $writer->addRow(Row::fromValuesWithStyles(SpreadsheetSafeText::row($headers), (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('243B31')));
            $money = (new Style)->setFormat('#,##0.00');
            foreach ($rows as $row) {
                $cells = [];
                foreach (array_values($row) as $i => $value) {
                    $cells[] = in_array($i, $moneyColumns, true) && $value !== null && $value !== ''
                        ? new NumericCell((string) $value, $money)
                        : Cell::fromValue(SpreadsheetSafeText::escape($value === null ? '' : (is_scalar($value) ? $value : (string) $value)));
                }
                $writer->addRow(new Row($cells));
            }
            $writer->close();
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        return response()->streamDownload(function () use ($path): void {
            readfile($path);
            @unlink($path);
        }, $filename.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store, max-age=0']);
    }
}
