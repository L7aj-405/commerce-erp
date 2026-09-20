<?php

namespace App\Services\Finance\Export;

use App\Enums\PaymentMethod;
use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class FinanceInvoiceExcelExport
{
    /** @param Collection<int, CreditNote>|null $creditNotes */
    public function build(Invoice $invoice, ?Collection $creditNotes = null): string
    {
        $invoice->loadMissing(['lines', 'salesOrder:id,order_number']);
        $creditNotes ??= $invoice->creditNotes()->where('status', 'issued')->with('lines')->orderBy('credit_note_date')->orderBy('id')->get();

        $tempPath = tempnam(sys_get_temp_dir(), 'finance_invoice_').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($tempPath);

        $this->writeInvoiceSheet($writer, $invoice);
        $this->writePaymentsSheet($writer, $invoice);
        if ($creditNotes->isNotEmpty()) {
            $this->writeCreditNotesSheet($writer, $creditNotes);
        }

        $writer->close();

        $bytes = file_get_contents($tempPath);
        @unlink($tempPath);

        return $bytes === false ? '' : $bytes;
    }

    private function writeInvoiceSheet(Writer $writer, Invoice $invoice): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Facture');
        foreach ([20, 18, 34, 18, 12, 14, 14, 12, 14, 14, 14] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $seller = $invoice->seller_snapshot ?? [];
        $writer->addRow(Row::fromValues(['Facture '.$invoice->invoice_number.($invoice->version > 1 ? ' · V'.$invoice->version : '')], $this->titleStyle()));
        $writer->addRow(Row::fromValues(['Société', ($seller['trade_name'] ?? null) ?: ($seller['legal_name'] ?? '')]));
        if (($seller['trade_name'] ?? null) && ($seller['legal_name'] ?? null)) {
            $writer->addRow(Row::fromValues(['Raison sociale', $seller['legal_name']]));
        }
        $writer->addRow(Row::fromValues(['Adresse', $seller['address'] ?? '']));
        $writer->addRow(Row::fromValues(['ICE', $seller['tax_identifier'] ?? '']));
        $writer->addRow(Row::fromValues(['RC', $seller['registration_number'] ?? '']));
        $writer->addRow(Row::fromValues(['IF / TP', $seller['patente_number'] ?? '']));
        $writer->addRow(Row::fromValues(['Téléphone', $seller['phone'] ?? '']));
        $writer->addRow(Row::fromValues(['Fax', $seller['fax'] ?? '']));
        $writer->addRow(Row::fromValues(['Email', $seller['email'] ?? '']));
        foreach (($seller['additional_identifiers'] ?? []) as $identifier) {
            $writer->addRow(Row::fromValues([$identifier['label'] ?? 'Identifiant', $identifier['value'] ?? '']));
        }
        if (data_get($seller, 'bank.rib')) {
            $writer->addRow(Row::fromValues(['Banque', data_get($seller, 'bank.name') ?? '']));
            $writer->addRow(Row::fromValues(['RIB', data_get($seller, 'bank.rib')]));
        }
        if ($seller['footer_text'] ?? null) {
            $writer->addRow(Row::fromValues(['Mention pied de page', $seller['footer_text']]));
        }
        if ($seller['logo'] ?? null) {
            $writer->addRow(Row::fromValues(['Logo', 'Configuré dans le snapshot officiel']));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['N° facture', $invoice->invoice_number, 'Version', $invoice->version, 'Date', $invoice->invoice_date->toDateString()]));
        $writer->addRow(Row::fromValues(['Commande', $invoice->salesOrder?->order_number ?? '—', 'Client', trim(($invoice->customer_company ?: $invoice->customer_name) ?: '') ?: '—']));
        $writer->addRow(Row::fromValues(['Adresse client', $invoice->billing_address ?? '', 'ICE client', $invoice->customer_tax_identifier ?? '']));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([
            'Référence/SKU', 'Type', 'Désignation', 'Variante/Présentation', 'Qté', 'PU HT',
            'Remise', 'TVA %', 'Total HT', 'Total TVA', 'Total TTC',
        ], $this->headerStyle()));

        foreach ($invoice->lines as $line) {
            $writer->addRow(Row::fromValues([
                $line->reference ?: $line->sku,
                $line->line_type->value,
                $line->description,
                trim(collect([$line->variant_name, $line->unit_label])->filter()->implode(' / ')),
                $this->number($line->quantity),
                $this->number($line->unit_price_excl_tax),
                $this->number($line->discount_amount),
                $this->number($line->tax_rate),
                $this->number($line->taxable_amount),
                $this->number($line->tax_amount),
                $this->number($line->total_incl_tax),
            ]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Totaux', '', '', '', '', '', '', '', $this->number($invoice->subtotal_excl_tax), $this->number($invoice->tax_total), $this->number($invoice->total_incl_tax)], $this->totalStyle()));
    }

    private function writePaymentsSheet(Writer $writer, Invoice $invoice): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Paiements');
        $writer->addRow(Row::fromValues(['N° paiement', 'Date', 'Mode', 'Montant alloué', 'Référence'], $this->headerStyle()));

        $payments = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.sales_order_id', $invoice->sales_order_id)
            ->where('payments.status', 'posted')
            ->orderBy('payments.payment_date')->orderBy('payments.id')
            ->get(['payments.payment_number', 'payments.payment_date', 'payments.method', 'payment_allocations.amount', 'payments.reference']);

        foreach ($payments as $payment) {
            $writer->addRow(Row::fromValues([
                $payment->payment_number,
                (string) $payment->payment_date,
                PaymentMethod::from($payment->method)->operationalLabel(),
                $this->number($payment->amount),
                $payment->reference ?? '',
            ]));
        }
    }

    /** @param Collection<int, CreditNote> $creditNotes */
    private function writeCreditNotesSheet(Writer $writer, Collection $creditNotes): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Avoirs');
        $writer->addRow(Row::fromValues([
            'N° avoir', 'Date', 'Motif', 'Référence', 'Désignation', 'Qté', 'PU HT',
            'Remise', 'TVA %', 'Total HT', 'Total TVA', 'Total TTC',
        ], $this->headerStyle()));

        foreach ($creditNotes as $note) {
            $note->loadMissing('lines');
            foreach ($note->lines as $line) {
                $writer->addRow(Row::fromValues([
                    $note->credit_note_number,
                    $note->credit_note_date->toDateString(),
                    $note->reason,
                    $line->reference,
                    $line->description,
                    $this->number($line->quantity),
                    $this->number($line->unit_price_excl_tax),
                    $this->number($line->discount_amount),
                    $this->number($line->tax_rate),
                    $this->number($line->taxable_amount),
                    $this->number($line->tax_amount),
                    $this->number($line->total_incl_tax),
                ]));
            }
            $writer->addRow(Row::fromValues(['Total avoir '.$note->credit_note_number, '', '', '', '', '', '', '', '', $this->number($note->subtotal_excl_tax), $this->number($note->tax_total), $this->number($note->total_incl_tax)], $this->totalStyle()));
        }
    }

    private function headerStyle(): Style
    {
        return (new Style)->setFontBold()->setBackgroundColor('EEEEEE');
    }

    private function titleStyle(): Style
    {
        return (new Style)->setFontBold()->setFontSize(13);
    }

    private function totalStyle(): Style
    {
        return (new Style)->setFontBold();
    }

    private function number(mixed $value): float
    {
        return round((float) ($value ?? '0'), 2);
    }
}
