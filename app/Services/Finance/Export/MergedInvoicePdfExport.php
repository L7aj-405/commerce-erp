<?php

namespace App\Services\Finance\Export;

use App\Contracts\PdfGenerator;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Store;
use App\Services\Finance\FinanceInvoiceReadModel;
use App\Services\Finance\FinancePeriod;
use App\Services\InvoiceDocumentRenderer;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Merges several invoices' existing official PDFs into ONE document, each
 * starting on a new page — without FPDI or any PDF-merge dependency, and
 * without changing DocumentPdfService::invoice() / the single-invoice PDF
 * path at all.
 *
 * How: InvoiceDocumentRenderer::html() (completely unmodified, the exact
 * renderer the single-invoice PDF already uses) produces one full HTML
 * document per invoice. This service extracts the `<head>` (styles) from
 * only the FIRST invoice — every invoice in a merge batch is required to
 * share the same `template_version`, so one shared stylesheet is always
 * correct — and the `<body>` inner content from every invoice, concatenating
 * them into a single HTML document with a `page-break-before: always`
 * wrapper between invoices, then renders that ONE document through the
 * existing PdfGenerator in a single Dompdf pass. No financial figure is
 * recalculated: each invoice's already-issued, already-immutable snapshot
 * data is reused exactly as-is.
 */
class MergedInvoicePdfExport
{
    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly InvoiceDocumentRenderer $renderer,
        private readonly FinanceInvoiceReadModel $invoices,
    ) {}

    /** @param  list<int>  $explicitIds */
    public function build(Organization $organization, string $mode, ?FinancePeriod $period, ?Store $store, array $explicitIds = []): array
    {
        $invoices = $this->selection($organization, $mode, $period, $store, $explicitIds);

        abort_if($invoices->isEmpty(), 422, 'Aucune facture ne correspond à cette sélection.');

        $templateVersions = $invoices->pluck('template_version')->unique();
        if ($templateVersions->count() > 1) {
            throw ValidationException::withMessages([
                'invoices' => 'Cette sélection mélange plusieurs versions de gabarit de facture ; elle ne peut pas être fusionnée en un seul PDF.',
            ]);
        }

        $html = $this->buildMergedHtml($invoices);
        $bytes = $this->pdf->generate($html, ['pageNumbers' => true]);

        return [
            'bytes' => $bytes,
            'filename' => 'Factures-'.now()->format('Y-m-d_His').'.pdf',
            'mime' => 'application/pdf',
        ];
    }

    /** @param  list<int>  $explicitIds
     * @return Collection<int, Invoice>
     */
    private function selection(Organization $organization, string $mode, ?FinancePeriod $period, ?Store $store, array $explicitIds): Collection
    {
        return match ($mode) {
            'issued' => $this->requirePeriod($period, fn (FinancePeriod $p) => $this->invoices->issuedInMonth($organization, $p, $store)),
            'settled' => $this->requirePeriod($period, fn (FinancePeriod $p) => $this->invoices->settledDuringMonth($organization, $p, $store)),
            'received_payment' => $this->requirePeriod($period, fn (FinancePeriod $p) => $this->invoices->receivedPaymentDuringMonth($organization, $p, $store)),
            'selection' => $this->invoices->forExplicitIds($organization, $explicitIds)
                ->each(fn (Invoice $invoice) => abort_unless($invoice->status === InvoiceStatus::Issued || $invoice->status === InvoiceStatus::Superseded, 422, 'Seules les factures émises ont un PDF officiel.')),
            default => throw ValidationException::withMessages(['mode' => 'Mode de sélection de factures invalide.']),
        };
    }

    private function requirePeriod(?FinancePeriod $period, Closure $callback): Collection
    {
        if (! $period) {
            throw ValidationException::withMessages(['month' => 'Un mois est requis pour ce mode de sélection.']);
        }

        return $callback($period);
    }

    /** @param  Collection<int, Invoice>  $invoices */
    private function buildMergedHtml(Collection $invoices): string
    {
        $head = '';
        $bodies = [];

        foreach ($invoices->values() as $index => $invoice) {
            $documentHtml = $this->renderer->html($invoice);

            if ($index === 0) {
                $head = $this->extract($documentHtml, 'head');
            }

            $bodies[] = $this->extract($documentHtml, 'body');
        }

        $pages = collect($bodies)
            ->map(fn (string $body, int $index) => '<div'.($index > 0 ? ' style="page-break-before: always"' : '').'>'.$body.'</div>')
            ->implode('');

        return "<!doctype html><html lang=\"fr\"><head>{$head}</head><body>{$pages}</body></html>";
    }

    private function extract(string $html, string $tag): string
    {
        if (preg_match("/<{$tag}[^>]*>(.*)<\/{$tag}>/is", $html, $matches)) {
            return $matches[1];
        }

        return '';
    }
}
