<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;

class InvoiceDocumentRenderer
{
    public function __construct(
        private readonly DocumentTemplateRegistry $templates,
        private readonly DocumentValueFormatter $format,
    ) {}

    /** @return array<string, mixed> */
    public function payload(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'salesOrder:id,order_number', 'issuedBy:id,name']);

        return [
            'kind' => 'invoice',
            'title' => __('documents.invoice', locale: config('documents.locale')),
            'watermark' => match ($invoice->status) {
                InvoiceStatus::Draft => __('documents.draft', locale: config('documents.locale')),
                InvoiceStatus::Cancelled => __('documents.cancelled', locale: config('documents.locale')),
                default => null,
            },
            'document' => [
                'number' => $invoice->invoice_number,
                'date' => $this->format->date($invoice->invoice_date),
                'order_number' => $invoice->salesOrder?->order_number,
                'currency' => $invoice->currency_code,
                'notes' => $invoice->notes,
            ],
            'seller' => $invoice->seller_snapshot,
            'buyer' => [
                'name' => $invoice->customer_name,
                'company' => $invoice->customer_company,
                'email' => $invoice->customer_email,
                'phone' => $invoice->customer_phone,
                'tax_identifier' => $invoice->customer_tax_identifier,
                'address' => $invoice->billing_address,
            ],
            'lines' => $invoice->lines->map(fn ($line) => [
                'description' => $line->description,
                'variant' => $line->variant_name,
                'sku' => $line->sku,
                'reference' => $line->reference,
                'unit' => $line->unit_label,
                'quantity' => $this->format->decimal($line->quantity),
                'unit_price' => $this->format->money($line->unit_price_excl_tax),
                'discount' => $this->format->money($line->discount_amount),
                'tax_name' => $line->tax_name,
                'tax_rate' => $this->format->decimal($line->tax_rate).' %',
                'tax_amount' => $this->format->money($line->tax_amount),
                'total' => $this->format->money($line->total_incl_tax),
            ])->all(),
            'totals' => [
                'subtotal' => $this->format->money($invoice->subtotal_excl_tax),
                'discount' => $this->format->money($invoice->discount_total),
                'tax' => $this->format->money($invoice->tax_total),
                'total' => $this->format->money($invoice->total_incl_tax),
            ],
            'template_version' => $invoice->template_version,
            'metadata' => [
                'issued_at' => $invoice->issued_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
                'issued_by' => $invoice->issuedBy?->name,
            ],
        ];
    }

    public function html(Invoice $invoice): string
    {
        $payload = $this->payload($invoice);

        return view($this->templates->invoiceView($invoice->template_version), $payload)->render();
    }
}
