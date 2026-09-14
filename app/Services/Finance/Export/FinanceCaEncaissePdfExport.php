<?php

namespace App\Services\Finance\Export;

use App\Contracts\PdfGenerator;
use App\Models\Organization;
use App\Models\Store;
use App\Services\DocumentSellerProfile;
use App\Services\DocumentValueFormatter;
use App\Services\Finance\FinanceCaEncaisseService;
use App\Services\Finance\FinancePeriod;
use Illuminate\Support\Carbon;

/**
 * Multi-month CA encaissé PDF, reusing the exact same section /
 * page-break-per-month mechanism as FinanceSituationPdfExport (one
 * `<section class="section">` per requested month, `page-break-before:
 * always` on every section but the first) through the same PdfGenerator /
 * Dompdf pipeline — no new PDF dependency.
 *
 * Branding is read from the existing Document Profile (DocumentSellerProfile
 * — the exact same source the invoice PDF uses), via organizationIdentity():
 * a Finance report is cross-store by nature (see FinanceAccessGuard), so
 * there is no single Store to snapshot against the way an issued invoice
 * has. Every field is optional and simply omitted when not configured — see
 * the blade view, which never prints a blank "ICE :" label or a broken
 * image tag.
 */
class FinanceCaEncaissePdfExport
{
    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly FinanceCaEncaisseService $caEncaisse,
        private readonly DocumentSellerProfile $sellerProfile,
        private readonly DocumentValueFormatter $format,
    ) {}

    /**
     * @param  list<FinancePeriod>  $periods
     * @return array{bytes: string, filename: string, mime: string}
     */
    public function build(Organization $organization, array $periods, ?Store $store, string $orientation = 'landscape'): array
    {
        $orientation = $orientation === 'portrait' ? 'portrait' : 'landscape';
        $seller = $this->sellerProfile->organizationIdentity($organization);
        $sections = array_map(fn (FinancePeriod $period) => $this->section($organization, $period, $store), $periods);

        $html = view('finance.ca-encaisse-pdf', [
            'seller' => $seller,
            'storeName' => $store?->name,
            'generatedAt' => now()->format('d/m/Y H:i'),
            'sections' => $sections,
        ])->render();

        $bytes = $this->pdf->generate($html, ['pageNumbers' => true, 'orientation' => $orientation]);

        $monthsLabel = count($periods) === 1 ? $periods[0]->month : $periods[0]->month.'_a_'.end($periods)->month;

        return [
            'bytes' => $bytes,
            'filename' => "CA-encaisse-{$monthsLabel}.pdf",
            'mime' => 'application/pdf',
        ];
    }

    /** @return array<string, mixed> */
    private function section(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        return [
            'label' => $period->label(),
            'total' => $this->format->money($this->caEncaisse->total($organization, $period, $store)),
            'rows' => $this->caEncaisse->cursor($organization, $period, $store)->map(fn (array $row) => [
                'payment_number' => $row['payment_number'],
                'sale_date' => $this->format->date(Carbon::parse($row['sale_date'])),
                'payment_date' => $this->format->date(Carbon::parse($row['payment_date'])),
                'reference' => $row['reference'],
                'designation' => $row['designation'],
                'customer' => $row['customer'],
                'method_label' => $row['method_label'],
                'amount' => $this->format->money($row['amount']),
                'status_label' => $row['status_label'],
            ])->all(),
        ];
    }
}
