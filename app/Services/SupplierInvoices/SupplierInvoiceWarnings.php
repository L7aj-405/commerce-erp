<?php

namespace App\Services\SupplierInvoices;

use App\Enums\SupplierInvoiceStatus;
use App\Models\Organization;
use App\Models\SupplierInvoice;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Soft (never blocking) warnings for a supplier invoice: loads the supplier's other non-void invoices and
 * delegates the decisions to the pure SupplierInvoiceDuplicateGuard.
 */
final class SupplierInvoiceWarnings
{
    private const CANDIDATE_LIMIT = 500;

    public function __construct(
        private readonly SupplierInvoiceDuplicateGuard $duplicates,
        private readonly SupplierInvoiceSettings $settings,
    ) {}

    /**
     * @param  list<string>  $dueDates  Y-m-d due dates of the installments (may be empty)
     * @return list<array{code: string, message: string}>
     */
    public function forValues(Organization $organization, int $supplierId, string $number, CarbonInterface|string $invoiceDate, string $totalTtc, array $dueDates = [], ?int $excludeInvoiceId = null): array
    {
        $candidates = SupplierInvoice::query()
            ->where('organization_id', $organization->getKey())
            ->where('supplier_id', $supplierId)
            ->where('status', '!=', SupplierInvoiceStatus::Void->value)
            ->when($excludeInvoiceId, fn ($query, $id) => $query->where('id', '!=', $id))
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get(['id', 'invoice_number', 'invoice_date', 'total_ttc'])
            ->map(fn (SupplierInvoice $row) => [
                'id' => $row->id,
                'invoice_number' => $row->invoice_number,
                'invoice_date' => $row->invoice_date->toDateString(),
                'total_ttc' => (string) $row->total_ttc,
            ])->all();

        $warnings = array_map(
            fn (array $warning) => ['code' => $warning['code'], 'message' => $warning['message']],
            $this->duplicates->warnings($number, $invoiceDate, $totalTtc, $candidates, $this->settings->duplicateWindowDays()),
        );

        $limit = $this->settings->paymentTermWarningDays();
        if ($limit !== null && $dueDates !== []) {
            $day = Carbon::parse($invoiceDate instanceof CarbonInterface ? $invoiceDate->format('Y-m-d') : $invoiceDate, 'UTC');
            foreach ($dueDates as $due) {
                if ($day->diffInDays(Carbon::parse($due, 'UTC'), false) > $limit) {
                    $warnings[] = ['code' => 'payment_term_long', 'message' => "Une échéance dépasse le délai de paiement habituel de {$limit} jours."];
                    break;
                }
            }
        }

        return $warnings;
    }

    /** @return list<array{code: string, message: string}> */
    public function forInvoice(SupplierInvoice $invoice): array
    {
        return $this->forValues(
            $invoice->organization,
            (int) $invoice->supplier_id,
            $invoice->invoice_number,
            $invoice->invoice_date,
            (string) $invoice->total_ttc,
            $invoice->installments->map(fn ($installment) => $installment->due_date->toDateString())->all(),
            (int) $invoice->getKey(),
        );
    }
}
