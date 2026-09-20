<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Support\Decimal;

class CreditNoteDocumentRenderer
{
    public function __construct(private readonly DocumentValueFormatter $format) {}

    /** @return array<string, mixed> */
    public function payload(CreditNote $note): array
    {
        $note->loadMissing([
            'invoice:id,invoice_number,version',
            'salesOrder:id,order_number',
            'lines.customerReturnLine:id,sku,variant_name',
            'issuedBy:id,name',
        ]);

        return [
            'note' => $note,
            'seller' => $note->seller_snapshot ?? [],
            'customer' => $note->customer_snapshot ?? [],
            'document' => [
                'number' => $note->credit_note_number,
                'date' => $this->format->date($note->credit_note_date),
                'invoice_number' => $note->invoice->invoice_number,
                'invoice_version' => $note->invoice->version,
                'order_number' => $note->salesOrder->order_number,
                'currency' => $note->currency_code,
                'reason' => $note->reason,
            ],
            'lines' => $note->lines->map(fn ($line) => [
                'reference' => $line->reference,
                'sku' => $line->customerReturnLine?->sku,
                'description' => $line->description,
                'variant' => $line->customerReturnLine?->variant_name,
                'presentation' => $line->unit_label,
                'quantity' => $this->format->decimal($line->quantity),
                'unit_price_ht' => $this->format->money($line->unit_price_excl_tax),
                'subtotal_ht' => $this->format->money($line->subtotal_excl_tax),
                'discount' => $this->format->money($line->discount_amount),
                'has_discount' => Decimal::compare($line->discount_amount, '0.0000') !== 0,
                'net_ht' => $this->format->money($line->taxable_amount),
                'tax_rate' => $this->format->decimal($line->tax_rate).'%',
                'tax_amount' => $this->format->money($line->tax_amount),
                'total_ttc' => $this->format->money($line->total_incl_tax),
            ])->all(),
            'tax_lines' => $this->taxLines($note),
            'totals' => [
                'subtotal' => $this->format->money($note->subtotal_excl_tax),
                'discount' => $this->format->money($note->discount_total),
                'net' => $this->format->money(Decimal::subtract($note->subtotal_excl_tax, $note->discount_total)),
                'tax' => $this->format->money($note->tax_total),
                'total' => $this->format->money($note->total_incl_tax),
            ],
            'has_discount' => Decimal::compare($note->discount_total, '0.0000') !== 0,
            'metadata' => [
                'issued_at' => $note->issued_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
                'issued_by' => $note->issuedBy?->name,
            ],
        ];
    }

    public function html(CreditNote $note): string
    {
        return view('documents.credit-note.v1', $this->payload($note))->render();
    }

    /** @return list<array{label:string,rate:string,base:string,amount:string}> */
    private function taxLines(CreditNote $note): array
    {
        $groups = [];
        foreach ($note->lines as $line) {
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

        return collect($groups)->sortBy('rate')->map(fn (array $group) => [
            'label' => $group['label'],
            'rate' => $group['rate'],
            'base' => $this->format->money($group['base']),
            'amount' => $this->format->money($group['amount']),
        ])->values()->all();
    }
}
