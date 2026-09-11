<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Decimal;
use App\Support\FrenchNumberToWords;

class InvoiceDocumentRenderer
{
    public function __construct(
        private readonly DocumentTemplateRegistry $templates,
        private readonly DocumentValueFormatter $format,
        private readonly FrenchNumberToWords $numberToWords,
    ) {}

    /** @return array<string, mixed> */
    public function payload(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'salesOrder:id,order_number', 'issuedBy:id,name']);

        $hasDiscount = Decimal::compare($invoice->discount_total, '0') !== 0
            || $invoice->lines->contains(fn ($line) => Decimal::compare($line->discount_amount, '0') !== 0);

        return [
            'kind' => 'invoice',
            'title' => __('documents.invoice', locale: config('documents.locale')),
            'watermark' => match ($invoice->status) {
                InvoiceStatus::Draft => __('documents.draft', locale: config('documents.locale')),
                InvoiceStatus::Cancelled => __('documents.cancelled', locale: config('documents.locale')),
                InvoiceStatus::Superseded => __('documents.superseded', locale: config('documents.locale')),
                default => null,
            },
            'has_discount' => $hasDiscount,
            'document' => [
                'number' => $invoice->invoice_number,
                'date' => $this->format->date($invoice->invoice_date),
                'order_number' => $invoice->salesOrder?->order_number,
                'currency' => $invoice->currency_code,
                'representative' => $invoice->representative_name,
                'payment_method' => $invoice->payment_method_summary,
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
                'reference' => $line->reference ?: $line->sku,
                'description' => $line->description,
                'variant' => $line->variant_name,
                'sku' => $line->sku,
                'presentation' => $line->unit_label ?: __('documents.default_unit', locale: config('documents.locale')),
                'quantity' => $this->format->decimal($line->quantity),
                'unit_price_ht' => $this->format->money($line->unit_price_excl_tax),
                'line_total_ht' => $this->format->money($line->taxable_amount),
                'discount' => $this->format->money($line->discount_amount),
                'has_discount' => Decimal::compare($line->discount_amount, '0') !== 0,
                'tax_rate' => $this->format->decimal($line->tax_rate).'%',
                'total' => $this->format->money($line->total_incl_tax),
            ])->all(),
            'tax_lines' => $this->taxLines($invoice),
            'totals' => [
                'subtotal' => $this->format->money($invoice->subtotal_excl_tax),
                'discount' => $this->format->money($invoice->discount_total),
                'net' => $this->format->money(Decimal::subtract($invoice->subtotal_excl_tax, $invoice->discount_total)),
                'tax' => $this->format->money($invoice->tax_total),
                'total' => $this->format->money($invoice->total_incl_tax),
            ],
            'amount_in_words' => $this->numberToWords->mad($invoice->total_incl_tax),
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

    /**
     * Group lines by tax rate so a single-rate invoice shows one "TVA (20%)" row
     * and a mixed invoice shows a clean per-rate breakdown. Never fakes a single
     * global rate.
     *
     * @return list<array{label:string, rate:string, base:string, amount:string}>
     */
    private function taxLines(Invoice $invoice): array
    {
        $groups = [];
        foreach ($invoice->lines as $line) {
            $key = $line->tax_rate.'|'.($line->tax_name ?? '');
            $groups[$key] ??= [
                'label' => $line->tax_name ?: 'TVA',
                'rate' => $this->format->decimal($line->tax_rate).'%',
                'base' => '0.0000',
                'amount' => '0.0000',
            ];
            $groups[$key]['base'] = Decimal::add($groups[$key]['base'], $line->taxable_amount);
            $groups[$key]['amount'] = Decimal::add($groups[$key]['amount'], $line->tax_amount);
        }

        return collect($groups)
            ->sortBy('rate')
            ->map(fn ($group) => [
                'label' => $group['label'],
                'rate' => $group['rate'],
                'base' => $this->format->money($group['base']),
                'amount' => $this->format->money($group['amount']),
            ])
            ->values()
            ->all();
    }
}
