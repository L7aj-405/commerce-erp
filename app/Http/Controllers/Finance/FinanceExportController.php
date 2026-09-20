<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\ActiveTenantContext;
use App\Services\Finance\Export\FinanceCaEncaisseExcelExport;
use App\Services\Finance\Export\FinanceCaEncaisseFullPackageExport;
use App\Services\Finance\Export\FinanceCaEncaisseInvoiceZipExport;
use App\Services\Finance\Export\FinanceCaEncaissePdfExport;
use App\Services\Finance\Export\FinanceSituationExcelExport;
use App\Services\Finance\Export\FinanceSituationPdfExport;
use App\Services\Finance\Export\MergedInvoicePdfExport;
use App\Services\Finance\FinanceAccessGuard;
use App\Services\Finance\FinancePeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * All three Finance exports require `finance.export` specifically — holding
 * only `finance.view` (read the dashboard) is not enough to download data.
 */
class FinanceExportController extends Controller
{
    public function xlsx(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, FinanceSituationExcelExport $export): Response
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeExport($request->user(), $organization);

        $period = $this->period($request);
        $store = $guard->resolveStore($organization, $request->query('store_id'));

        $bytes = $export->build($organization, $period, $store);

        return $this->download(
            $bytes,
            "Situation-{$period->month}.xlsx",
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
    }

    public function pdf(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, FinanceSituationPdfExport $export): Response
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeExport($request->user(), $organization);

        $months = (array) $request->query('months', [$request->query('month')]);
        $months = array_values(array_filter($months));
        if ($months === []) {
            $months = [FinancePeriod::current()->month];
        }
        $periods = FinancePeriod::fromMonths($months);
        $store = $guard->resolveStore($organization, $request->query('store_id'));

        $result = $export->build($organization, $periods, $store);

        return $this->download($result['bytes'], $result['filename'], $result['mime']);
    }

    public function invoicesPdf(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, MergedInvoicePdfExport $export): Response
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeExport($request->user(), $organization);

        $data = $request->validate([
            'mode' => ['required', Rule::in(['issued', 'settled', 'received_payment', 'selection'])],
            'month' => ['nullable', 'date_format:Y-m'],
            'invoice_ids' => ['nullable', 'array'],
            'invoice_ids.*' => ['integer'],
        ]);

        $period = ($data['month'] ?? null) ? FinancePeriod::fromMonth($data['month']) : null;
        $store = $guard->resolveStore($organization, $request->query('store_id'));

        $result = $export->build(
            $organization,
            $data['mode'],
            $period,
            $store,
            $data['invoice_ids'] ?? [],
        );

        return $this->download($result['bytes'], $result['filename'], $result['mime']);
    }

    public function caEncaisseXlsx(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, FinanceCaEncaisseExcelExport $export): Response
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeExport($request->user(), $organization);

        $period = $this->period($request);
        $store = $guard->resolveStore($organization, $request->query('store_id'));

        $bytes = $export->build($organization, $period, $store);

        return $this->download(
            $bytes,
            "CA-encaisse-{$period->month}.xlsx",
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
    }

    public function caEncaissePdf(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, FinanceCaEncaissePdfExport $export): Response
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeExport($request->user(), $organization);

        $months = (array) $request->query('months', [$request->query('month')]);
        $months = array_values(array_filter($months));
        if ($months === []) {
            $months = [FinancePeriod::current()->month];
        }
        $periods = FinancePeriod::fromMonths($months);
        $store = $guard->resolveStore($organization, $request->query('store_id'));

        // An explicit, validated orientation only — never an arbitrary query
        // value passed through to Dompdf. Absent defaults to landscape: CA
        // encaissé has nine columns and reads far better wide than an
        // invoice-style portrait document.
        $data = $request->validate(['orientation' => ['nullable', Rule::in(['portrait', 'landscape'])]]);
        $orientation = $data['orientation'] ?? 'landscape';

        $result = $export->build($organization, $periods, $store, $orientation);

        return $this->download($result['bytes'], $result['filename'], $result['mime']);
    }

    /**
     * "Exporter les factures (.ZIP)" — the individual official PDF for every
     * issued invoice that received a Posted payment during the selected
     * month/store scope (the exact same scope as caEncaisseXlsx/caEncaissePdf
     * above), one file per invoice, deduplicated by invoice identity. A
     * read-only export: it never stamps, issues, or otherwise mutates a
     * document — see FinanceCaEncaisseInvoiceZipExport's own doc for the
     * memory/temp-file strategy.
     */
    public function caEncaisseInvoicesZip(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, FinanceCaEncaisseInvoiceZipExport $export): BinaryFileResponse
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeExport($request->user(), $organization);

        $period = $this->period($request);
        $store = $guard->resolveStore($organization, $request->query('store_id'));

        $result = $export->build($organization, $period, $store);

        return response()->download($result['path'], $result['filename'], [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    public function caEncaisseFullPackage(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, FinanceCaEncaisseFullPackageExport $export): BinaryFileResponse
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeExport($request->user(), $organization);

        $period = $this->period($request);
        $store = $guard->resolveStore($organization, $request->query('store_id'));

        $result = $export->build($organization, $period, $store);

        return response()->download($result['path'], $result['filename'], [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    private function period(Request $request): FinancePeriod
    {
        $month = $request->query('month');

        return $month ? FinancePeriod::fromMonth($month) : FinancePeriod::current();
    }

    private function download(string $bytes, string $filename, string $mime): Response
    {
        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($bytes),
        ]);
    }
}
