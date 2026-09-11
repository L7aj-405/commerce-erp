<?php

namespace App\Services;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Support\Decimal;
use App\Support\FrenchNumberToWords;

class QuotationDocumentRenderer
{
    public function __construct(
        private readonly DocumentTemplateRegistry $templates,
        private readonly DocumentValueFormatter $format,
        private readonly FrenchNumberToWords $numberToWords,
    ) {}

    /** @return array<string, mixed> */
    public function payload(Quotation $quotation): array
    {
        $quotation->loadMissing(['lines', 'issuedBy:id,name', 'rootQuotation:id,quotation_number']);

        $hasDiscount = Decimal::compare($quotation->discount_total, '0') !== 0
            || $quotation->lines->contains(fn ($line) => Decimal::compare($line->discount_amount, '0') !== 0);

        $locale = config('documents.locale');
        $isRevision = $quotation->isRevision();

        return [
            'kind' => 'quotation',
            'title' => __('documents.quotation', locale: $locale),
            'watermark' => match ($quotation->status) {
                QuotationStatus::Draft => __('documents.draft', locale: $locale),
                QuotationStatus::Rejected => __('documents.rejected', locale: $locale),
                QuotationStatus::Expired => __('documents.expired', locale: $locale),
                QuotationStatus::Superseded => __('documents.superseded_quotation', locale: $locale),
                default => null,
            },
            'has_discount' => $hasDiscount,
            'document' => [
                'number' => $quotation->quotation_number,
                'date' => $this->format->date($quotation->quotation_date),
                'valid_until' => $this->format->date($quotation->valid_until),
                'currency' => $quotation->currency_code,
                'representative' => $quotation->representative_name,
                'notes' => $quotation->notes,
                'terms' => $quotation->terms,
                // Same commercial proposal, revised — shown as a sub-title so the
                // customer understands DEV-1/2026 · Révision 2 is one offer.
                'revision' => $isRevision
                    ? __('documents.revision', locale: $locale).' '.$quotation->revision_number
                    : null,
                'root_number' => $isRevision
                    ? ($quotation->rootQuotation?->quotation_number ?? $quotation->quotation_number)
                    : null,
            ],
            'seller' => $quotation->seller_snapshot,
            'buyer' => [
                'name' => $quotation->customer_name,
                'company' => $quotation->customer_company,
                'email' => $quotation->customer_email,
                'phone' => $quotation->customer_phone,
                'tax_identifier' => $quotation->customer_tax_identifier,
                'address' => $quotation->billing_address,
            ],
            'lines' => $quotation->lines->map(fn ($line) => [
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
            'tax_lines' => $this->taxLines($quotation),
            'totals' => [
                'subtotal' => $this->format->money($quotation->subtotal_excl_tax),
                'discount' => $this->format->money($quotation->discount_total),
                'net' => $this->format->money(Decimal::subtract($quotation->subtotal_excl_tax, $quotation->discount_total)),
                'tax' => $this->format->money($quotation->tax_total),
                'total' => $this->format->money($quotation->total_incl_tax),
            ],
            'amount_in_words' => $this->numberToWords->mad($quotation->total_incl_tax),
            'template_version' => $quotation->template_version,
            'metadata' => [
                'issued_at' => $quotation->issued_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
                'issued_by' => $quotation->issuedBy?->name,
            ],
        ];
    }

    public function html(Quotation $quotation): string
    {
        return view($this->templates->quotationView($quotation->template_version), $this->payload($quotation))->render();
    }

    /** @return list<array{label:string, rate:string, base:string, amount:string}> */
    private function taxLines(Quotation $quotation): array
    {
        $groups = [];
        foreach ($quotation->lines as $line) {
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
