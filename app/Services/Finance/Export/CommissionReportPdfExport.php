<?php

namespace App\Services\Finance\Export;

use App\Contracts\PdfGenerator;
use App\Models\Organization;
use App\Services\Commissions\CommissionDashboardService;
use App\Services\Commissions\CommissionReportFilters;
use App\Services\DocumentValueFormatter;

/**
 * Printable commission summary on the existing PdfGenerator / Dompdf
 * pipeline: KPIs + salesperson summary only. Detailed ledger lines are
 * deliberately left to the XLSX export.
 */
class CommissionReportPdfExport
{
    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly CommissionDashboardService $dashboard,
        private readonly DocumentValueFormatter $format,
    ) {}

    /** @return array{bytes:string,filename:string,mime:string,rows:int} */
    public function build(Organization $organization, CommissionReportFilters $filters, bool $withCosts, string $filterLabel): array
    {
        $kpis = $this->dashboard->kpis($organization, $filters);
        $salespeople = $this->dashboard->bySalesperson($organization, $filters);
        $money = fn (?string $value) => $this->format->money($value);
        $percent = fn (?string $value) => $value === null ? 'n/d' : $this->format->money($value).' %';

        $html = view('finance.commissions-pdf', [
            'organizationName' => $organization->name,
            'from' => $filters->from,
            'to' => $filters->to,
            'filterLabel' => $filterLabel,
            'generatedAt' => now()->format('d/m/Y H:i'),
            'withCosts' => $withCosts,
            'kpis' => [
                'gross' => $money($kpis['gross']), 'reversals' => $money($kpis['reversals']), 'net' => $money($kpis['net']),
                'pending' => $money($kpis['pending']), 'approved' => $money($kpis['approved']), 'paid' => $money($kpis['paid']),
                'revenue' => $money($kpis['revenue']), 'margin' => $money($kpis['margin']),
                'margin_rate' => $percent($kpis['margin_rate']), 'ratio' => $percent($kpis['commission_to_margin_ratio']),
                'order_count' => $kpis['order_count'], 'entry_count' => $kpis['entry_count'],
            ],
            'rows' => array_map(fn (array $row) => [
                'name' => $row['salesperson_name'], 'unattributed' => $row['is_unattributed'], 'orders' => $row['orders'],
                'revenue' => $money($row['revenue']), 'margin' => $money($row['margin']), 'gross' => $money($row['gross']),
                'reversals' => $money($row['reversals']), 'net' => $money($row['net']), 'pending' => $money($row['pending']),
                'approved' => $money($row['approved']), 'paid' => $money($row['paid']),
            ], $salespeople),
        ])->render();

        return [
            'bytes' => $this->pdf->generate($html, ['pageNumbers' => true, 'orientation' => 'landscape']),
            'filename' => "Commissions-{$filters->from}_{$filters->to}.pdf",
            'mime' => 'application/pdf',
            'rows' => count($salespeople),
        ];
    }
}
