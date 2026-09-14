<?php

namespace App\Services\Finance\Export;

use App\Contracts\PdfGenerator;
use App\Models\Organization;
use App\Models\Store;
use App\Services\DocumentValueFormatter;
use App\Services\Finance\FinanceInvoiceReadModel;
use App\Services\Finance\FinanceMonthlyReportService;
use App\Services\Finance\FinancePeriod;
use App\Support\Decimal;

/**
 * Multi-month Situation mensuelle PDF, reusing the existing PdfGenerator /
 * Dompdf pipeline unchanged — no FPDI or other PDF-merge dependency. One
 * complete HTML document is built (one `<section class="section">` per
 * requested month, each with `page-break-before: always` except the first),
 * then rendered in a single Dompdf pass. A long month naturally spans
 * several physical pages; the next month's `page-break-before` still forces
 * it to start on a fresh page regardless.
 */
class FinanceSituationPdfExport
{
    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly FinanceMonthlyReportService $report,
        private readonly FinanceInvoiceReadModel $invoices,
        private readonly DocumentValueFormatter $format,
    ) {}

    /**
     * @param  list<FinancePeriod>  $periods
     * @return array{bytes: string, filename: string, mime: string}
     */
    public function build(Organization $organization, array $periods, ?Store $store): array
    {
        $sections = array_map(fn (FinancePeriod $period) => $this->section($organization, $period, $store), $periods);

        $html = view('finance.situation-pdf', [
            'organizationName' => $organization->name,
            'storeName' => $store?->name,
            'generatedAt' => now()->format('d/m/Y H:i'),
            'sections' => $sections,
        ])->render();

        $bytes = $this->pdf->generate($html, ['pageNumbers' => true]);

        $monthsLabel = count($periods) === 1 ? $periods[0]->month : $periods[0]->month.'_a_'.end($periods)->month;

        return [
            'bytes' => $bytes,
            'filename' => "Situation-mensuelle-{$monthsLabel}.pdf",
            'mime' => 'application/pdf',
        ];
    }

    /** @return array<string, mixed> */
    private function section(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $situation = $this->report->situation($organization, $period, $store);
        $invoices = $this->invoices->issuedInMonth($organization, $period, $store);
        $enriched = $this->invoices->withPaymentSummaries($invoices, $period->end);

        return [
            'label' => $period->label(),
            'ventes' => $this->format->money($situation['ventes']),
            'facturation' => $this->format->money($situation['facturation']),
            'encaissements' => $this->format->money($situation['encaissements']),
            'creances_debut' => $this->format->money($situation['creances_debut']),
            'creances_fin' => $this->format->money($situation['creances_fin']),
            'has_variance' => Decimal::compare($situation['reconciliation']['variance'], '0.0000') !== 0,
            'variance' => $this->format->money($situation['reconciliation']['unapplied_payments']),
            'invoices' => $enriched->map(fn (array $row) => [
                'invoice_number' => $row['invoice_number'],
                'invoice_date' => $row['invoice_date'],
                'customer' => trim(($row['customer_company'] ?: $row['customer_name']) ?: '') ?: '—',
                'total_incl_tax' => $this->format->money($row['total_incl_tax']),
                'status_label' => match ($row['status']) {
                    'paid' => 'Soldée',
                    'partial' => 'Partiellement payée',
                    default => 'Impayée',
                },
            ])->all(),
        ];
    }
}
