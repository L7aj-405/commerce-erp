<?php

namespace App\Services;

use App\Models\SalesOrder;

/** Builds an exact, read-only commercial snapshot without float conversion. */
class SalesOrderRevisionSnapshot
{
    /** @return array<string, mixed> */
    public function make(SalesOrder $order): array
    {
        $order->loadMissing(['lines.allocations.warehouse', 'customer']);

        return [
            'order_number' => $order->order_number,
            'sale_date' => $order->sale_date->toDateString(),
            'currency_code' => $order->currency_code,
            'customer' => [
                'id' => $order->customer_id,
                'name' => $order->customer_name,
                'company' => $order->customer_company,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
            ],
            'totals' => [
                'subtotal_excl_tax' => (string) $order->subtotal_excl_tax,
                'discount_total' => (string) $order->discount_total,
                'tax_total' => (string) $order->tax_total,
                'total_incl_tax' => (string) $order->total_incl_tax,
            ],
            'lines' => $order->lines->map(fn ($line) => [
                'id' => $line->id,
                'position' => $line->position,
                'line_type' => $line->line_type->value,
                'product_variant_id' => $line->product_variant_id,
                'product_name' => $line->product_name,
                'variant_name' => $line->variant_name,
                'sku' => $line->sku,
                'reference' => $line->reference,
                'unit_label' => $line->unit_label,
                'quantity' => (string) $line->quantity,
                'unit_price_excl_tax' => (string) $line->unit_price_excl_tax,
                'unit_price_incl_tax' => (string) $line->unit_price_incl_tax,
                'tax_name' => $line->tax_name,
                'tax_rate' => (string) $line->tax_rate,
                'discount_type' => $line->discount_type->value,
                'discount_value' => (string) $line->discount_value,
                'subtotal_excl_tax' => (string) $line->subtotal_excl_tax,
                'discount_amount' => (string) $line->discount_amount,
                'taxable_amount' => (string) $line->taxable_amount,
                'tax_amount' => (string) $line->tax_amount,
                'total_incl_tax' => (string) $line->total_incl_tax,
                'allocations' => $line->allocations->map(fn ($allocation) => [
                    'warehouse_id' => $allocation->warehouse_id,
                    'warehouse' => $allocation->warehouse?->name,
                    'quantity' => (string) $allocation->quantity,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
