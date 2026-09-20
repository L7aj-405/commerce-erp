<?php

namespace App\Services\Finance\Export;

use App\Enums\PaymentStatus;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Store;
use App\Services\DocumentPdfService;
use App\Services\Finance\FinanceInvoiceReadModel;
use App\Services\Finance\FinancePeriod;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * "Exporter les factures (.ZIP)" for CA encaissé — the SAME invoice
 * selection `MergedInvoicePdfExport`'s 'received_payment' mode already uses
 * (FinanceInvoiceReadModel::receivedPaymentDuringMonth: issued invoices
 * whose sales order received a Posted payment allocation in the period),
 * i.e. the exact filter scope the CA encaissé screen/Excel/PDF exports
 * already share (identical organization/period/store WHERE clauses — see
 * FinanceCaEncaisseService::baseQuery). Unlike MergedInvoicePdfExport this
 * never merges anything: each invoice's existing official PDF
 * (DocumentPdfService::invoice(), completely unmodified) is written to the
 * archive as its own file, and the underlying invoice rows/lines/stamp
 * apposition are only ever read, never mutated — a Finance export has zero
 * document-state side effects.
 *
 * Memory strategy (§17): PDF bytes for at most one invoice at a time are
 * held in PHP memory — each is written straight to its own temp file and
 * added to the archive via ZipArchive::addFile() (which streams from disk at
 * close(), unlike addFromString(), which would keep every invoice's bytes
 * resident until the archive is finalized). An explicit MAX_INVOICES caps
 * worst-case disk/CPU for one request rather than crashing PHP on an
 * unexpectedly huge month.
 */
class FinanceCaEncaisseInvoiceZipExport
{
    /**
     * A generous but explicit ceiling for a single synchronous export. Well
     * above any real month's invoice-with-payment count for a single
     * organization/store in this product's current usage; queueing a
     * background job is not warranted below this, per the Finance V1 export
     * architecture audit (§17).
     */
    private const MAX_INVOICES = 500;

    public function __construct(
        private readonly FinanceInvoiceReadModel $invoices,
        private readonly DocumentPdfService $documents,
    ) {}

    /** @return array{path: string, filename: string} absolute path to the built ZIP — caller streams it, then deletes it */
    public function build(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        ['invoices' => $eligible, 'creditNotes' => $creditNotes] = $this->documents($organization, $period, $store);

        abort_if($eligible->isEmpty(), 422, 'Aucune facture émise n’est associée à la période sélectionnée.');

        if ($eligible->count() + $creditNotes->count() > self::MAX_INVOICES) {
            abort(422, sprintf(
                'Cette sélection contient %d factures, au-delà de la limite de %d pour un export ZIP en une fois. '
                .'Filtrez par magasin pour réduire la sélection.',
                $eligible->count() + $creditNotes->count(),
                self::MAX_INVOICES,
            ));
        }

        // §24 — one batched query for the stamp snapshot across the whole
        // selection, instead of InvoiceDocumentRenderer lazy-loading it once
        // per invoice inside the loop below.
        $eligible->load('stampApposition');

        $collectedAmounts = $this->collectedAmountsBySalesOrder(
            $organization,
            $period,
            $store,
            $eligible->pluck('sales_order_id')->all(),
        );

        $workingDir = $this->workingDirectory($organization);

        $zipPath = tempnam(sys_get_temp_dir(), 'finance_invoice_zip_');
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            $this->cleanup($workingDir);
            @unlink($zipPath);
            throw new RuntimeException('Impossible de créer l’archive ZIP.');
        }

        $usedNames = [];
        $manifestRows = [];
        $creditNoteManifestRows = [];
        $tempPdfPaths = [];

        try {
            foreach ($eligible as $invoice) {
                $document = $this->documents->invoice($invoice);
                $entryName = $this->uniqueEntryName(
                    'Factures/'.$this->safeDocumentFolder($invoice->invoice_number).'/'.$document['filename'],
                    $invoice->id,
                    $usedNames,
                );

                $pdfPath = $workingDir.DIRECTORY_SEPARATOR.Str::random(24).'.pdf';
                file_put_contents($pdfPath, $document['bytes']);
                $tempPdfPaths[] = $pdfPath;
                $zip->addFile($pdfPath, $entryName);

                $manifestRows[] = $this->manifestRow($invoice, $entryName, $collectedAmounts[$invoice->sales_order_id] ?? '0.0000');

                // Free this invoice's PDF bytes before rendering the next one —
                // never accumulate the whole selection's bytes in one array.
                unset($document);
            }

            foreach ($creditNotes as $creditNote) {
                $document = $this->documents->creditNote($creditNote);
                $invoiceNumber = $creditNote->invoice?->invoice_number ?: 'Facture';
                $entryName = $this->uniqueEntryName(
                    'Factures/'.$this->safeDocumentFolder($invoiceNumber).'/'.$document['filename'],
                    $creditNote->id,
                    $usedNames,
                );
                $pdfPath = $workingDir.DIRECTORY_SEPARATOR.Str::random(24).'.pdf';
                file_put_contents($pdfPath, $document['bytes']);
                $tempPdfPaths[] = $pdfPath;
                $zip->addFile($pdfPath, $entryName);
                $creditNoteManifestRows[] = [
                    $creditNote->credit_note_number,
                    $creditNote->credit_note_date->toDateString(),
                    $creditNote->invoice->invoice_number,
                    (string) $creditNote->invoice->version,
                    (string) $creditNote->total_incl_tax,
                    $entryName,
                ];
                unset($document);
            }

            $zip->addFromString('manifest.csv', $this->manifest($manifestRows));
            if ($creditNoteManifestRows !== []) {
                $zip->addFromString('avoirs-manifest.csv', $this->creditNoteManifest($creditNoteManifestRows));
            }
        } finally {
            $zip->close();
            foreach ($tempPdfPaths as $path) {
                @unlink($path);
            }
            @rmdir($workingDir);
        }

        return [
            'path' => $zipPath,
            'filename' => "Factures_{$period->month}.zip",
        ];
    }

    /** @return array{invoices: \Illuminate\Support\Collection<int, Invoice>, creditNotes: \Illuminate\Support\Collection<int, CreditNote>} */
    public function documents(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $eligible = $this->invoices->receivedPaymentDuringMonth($organization, $period, $store);

        $creditNotes = CreditNote::query()
            ->where('organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->where('status', 'issued')
            ->whereIn('invoice_id', $eligible->pluck('id'))
            ->with('invoice:id,invoice_number,version')
            ->orderBy('credit_note_date')->orderBy('id')->get();

        return ['invoices' => $eligible, 'creditNotes' => $creditNotes];
    }

    /**
     * §19 — a fresh, unpredictable, organization-scoped directory per export
     * under the system temp dir (same base OpenSpout/tempnam-based exports
     * already use — see FinanceCaEncaisseExcelExport), never the public disk.
     * Also sweeps away any directory from a PAST export for this
     * organization left behind by an aborted request (e.g. the process was
     * killed before its `finally` ran) — best-effort, never fatal.
     */
    private function workingDirectory(Organization $organization): string
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'finance-invoice-zip';
        $this->sweepStale($base, $organization->getKey());

        $dir = $base.DIRECTORY_SEPARATOR.'org'.$organization->getKey().'-'.Str::random(32);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Impossible de préparer le répertoire temporaire d’export.');
        }

        return $dir;
    }

    private function sweepStale(string $base, int $organizationId): void
    {
        if (! is_dir($base)) {
            return;
        }

        foreach (glob($base.DIRECTORY_SEPARATOR."org{$organizationId}-*", GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < now()->subHour()->timestamp) {
                $this->cleanup($dir);
            }
        }
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private function safeDocumentFolder(?string $number): string
    {
        return trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $number), '-') ?: 'document';
    }

    /**
     * @param  list<int>  $salesOrderIds
     * @return array<int, string> sales_order_id => amount collected in the period
     */
    private function collectedAmountsBySalesOrder(Organization $organization, FinancePeriod $period, ?Store $store, array $salesOrderIds): array
    {
        if ($salesOrderIds === []) {
            return [];
        }

        // Same WHERE clauses as FinanceCaEncaisseService::baseQuery — the
        // manifest's "collected this period" figure must reconcile exactly
        // with the CA encaissé screen/export for the same scope.
        return DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.organization_id', $organization->getKey())
            ->where('payments.status', PaymentStatus::Posted->value)
            ->whereDate('payments.payment_date', '>=', $period->start->toDateString())
            ->whereDate('payments.payment_date', '<=', $period->end->toDateString())
            ->when($store, fn ($query) => $query->where('payments.store_id', $store->getKey()))
            ->whereIn('payment_allocations.sales_order_id', $salesOrderIds)
            ->selectRaw('payment_allocations.sales_order_id as sales_order_id, SUM(payment_allocations.amount) as total')
            ->groupBy('payment_allocations.sales_order_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->sales_order_id => Decimal::normalize((string) $row->total)])
            ->all();
    }

    /** @param  array<string, true>  $used */
    private function uniqueEntryName(string $filename, int $invoiceId, array &$used): string
    {
        if (! isset($used[$filename])) {
            $used[$filename] = true;

            return $filename;
        }

        // Collision fallback only — the invisible database id never replaces
        // the visible invoice number, it only disambiguates the archive entry.
        $dir = pathinfo($filename, PATHINFO_DIRNAME);
        $fallback = pathinfo($filename, PATHINFO_FILENAME)."-{$invoiceId}.pdf";
        if ($dir !== '.' && $dir !== '') {
            $fallback = $dir.'/'.$fallback;
        }
        $used[$fallback] = true;

        return $fallback;
    }

    /** @return list<string> */
    private function manifestRow(Invoice $invoice, string $filename, string $collectedThisPeriod): array
    {
        return [
            $invoice->invoice_number,
            (string) $invoice->version,
            $invoice->invoice_date->toDateString(),
            trim($invoice->customer_company ?: $invoice->customer_name ?: '') ?: '—',
            (string) $invoice->total_incl_tax,
            $collectedThisPeriod,
            $invoice->stampApposition ? 'Cachetée' : 'Non cachetée',
            $filename,
        ];
    }

    /** @param  list<list<string>>  $rows */
    private function manifest(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['N° facture', 'Version', 'Date facture', 'Client', 'Total TTC', 'Encaissé sur la période', 'Cachet', 'Fichier'], ';');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /** @param  list<list<string>>  $rows */
    private function creditNoteManifest(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['N° avoir', 'Date avoir', 'Facture d’origine', 'Version facture', 'Total TTC crédité', 'Fichier'], ';');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
