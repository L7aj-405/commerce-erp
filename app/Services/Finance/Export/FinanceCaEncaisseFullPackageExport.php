<?php

namespace App\Services\Finance\Export;

use App\Models\Organization;
use App\Models\Store;
use App\Services\DocumentPdfService;
use App\Services\Finance\FinancePeriod;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class FinanceCaEncaisseFullPackageExport
{
    public function __construct(
        private readonly FinanceCaEncaisseExcelExport $caExcel,
        private readonly FinanceCaEncaisseInvoiceZipExport $invoiceZip,
        private readonly FinanceInvoiceExcelExport $invoiceExcel,
        private readonly DocumentPdfService $documents,
    ) {}

    /** @return array{path: string, filename: string} */
    public function build(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        ['invoices' => $invoices, 'creditNotes' => $creditNotes] = $this->invoiceZip->documents($organization, $period, $store);
        abort_if($invoices->isEmpty(), 422, 'Aucune facture émise n’est associée à la période sélectionnée.');

        $workingDir = $this->workingDirectory($organization);
        $zipPath = tempnam(sys_get_temp_dir(), 'finance_ca_package_');
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            $this->cleanup($workingDir);
            @unlink($zipPath);
            throw new RuntimeException('Impossible de créer le package ZIP.');
        }

        $root = 'Package_CA_'.$period->month.'/';
        $tempFiles = [];
        $usedNames = [];

        try {
            $zip->addFromString($root.'CA_Encaisse_'.$period->month.'.xlsx', $this->caExcel->build($organization, $period, $store));

            $creditNotesByInvoice = $creditNotes->groupBy('invoice_id');
            foreach ($invoices as $invoice) {
                $relatedCreditNotes = $creditNotesByInvoice->get($invoice->id, collect());

                $pdf = $this->documents->invoice($invoice);
                $pdfName = $this->uniqueEntryName($root.'Factures_PDF/'.$pdf['filename'], $invoice->id, $usedNames);
                $pdfPath = $this->writeTemp($workingDir, $pdf['bytes'], 'pdf');
                $tempFiles[] = $pdfPath;
                $zip->addFile($pdfPath, $pdfName);
                unset($pdf);

                $xlsxName = $this->uniqueEntryName($root.'Factures_Excel/'.$this->excelFilename('Facture', $invoice->invoice_number), $invoice->id, $usedNames);
                $zip->addFromString($xlsxName, $this->invoiceExcel->build($invoice, $relatedCreditNotes));

                foreach ($relatedCreditNotes as $creditNote) {
                    $creditPdf = $this->documents->creditNote($creditNote);
                    $creditPdfName = $this->uniqueEntryName($root.'Factures_PDF/'.$creditPdf['filename'], $creditNote->id, $usedNames);
                    $creditPdfPath = $this->writeTemp($workingDir, $creditPdf['bytes'], 'pdf');
                    $tempFiles[] = $creditPdfPath;
                    $zip->addFile($creditPdfPath, $creditPdfName);
                    unset($creditPdf);
                }
            }
        } finally {
            $zip->close();
            foreach ($tempFiles as $path) {
                @unlink($path);
            }
            @rmdir($workingDir);
        }

        return [
            'path' => $zipPath,
            'filename' => 'Package_CA_'.$period->month.'.zip',
        ];
    }

    private function workingDirectory(Organization $organization): string
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'finance-ca-package';
        $dir = $base.DIRECTORY_SEPARATOR.'org'.$organization->getKey().'-'.Str::random(32);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Impossible de préparer le répertoire temporaire du package.');
        }

        return $dir;
    }

    private function writeTemp(string $dir, string $bytes, string $extension): string
    {
        $path = $dir.DIRECTORY_SEPARATOR.Str::random(24).'.'.$extension;
        file_put_contents($path, $bytes);

        return $path;
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    /** @param array<string, true> $used */
    private function uniqueEntryName(string $name, int $id, array &$used): string
    {
        if (! isset($used[$name])) {
            $used[$name] = true;

            return $name;
        }

        $dir = pathinfo($name, PATHINFO_DIRNAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $fallback = pathinfo($name, PATHINFO_FILENAME).'-'.$id.($extension ? '.'.$extension : '');
        if ($dir !== '.' && $dir !== '') {
            $fallback = $dir.'/'.$fallback;
        }
        $used[$fallback] = true;

        return $fallback;
    }

    private function excelFilename(string $prefix, ?string $number): string
    {
        $safe = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $number), '-') ?: 'document';

        return $prefix.'_'.$safe.'.xlsx';
    }
}
