<?php

namespace App\Http\Requests\Sales;

use App\Enums\SalesOrderDiscountType;
use App\Enums\SalesOrderLineType;
use Illuminate\Validation\Rule;

/**
 * Canonical Sales Order validation rules, shared by the web controllers and
 * the MCP Sales Order tools so both validate identically.
 */
final class SalesOrderRules
{
    /** @return array<string, mixed> */
    public static function header(int $organizationId): array
    {
        return [
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active'))],
            'sale_date' => ['required', 'date'], 'currency_code' => ['required', 'string', 'size:3', 'uppercase'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'salesperson_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * The modern editor no longer sends a per-line `warehouse_id` (sourcing is
     * resolved server-side) and lets a custom line's price be entered HT or TTC
     * (`price_input_mode` + `unit_price`). The legacy `unit_price_excl_tax` HT
     * field is still accepted for backward compatibility (Devis conversion, API).
     *
     * @return array<string, mixed>
     */
    public static function line(): array
    {
        return [
            'line_type' => ['required', Rule::enum(SalesOrderLineType::class)],
            'product_variant_id' => ['nullable', 'required_if:line_type,catalog', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'required_if:line_type,custom', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'], 'unit_label' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'regex:/^[1-9]\d*(?:\.0{1,4})?$/'],
            'price_input_mode' => ['nullable', 'in:ht,ttc'],
            'unit_price' => ['nullable', 'decimal:0,4', 'gte:0'],
            'unit_price_excl_tax' => ['nullable', 'decimal:0,4', 'gte:0'],
            'tax_rate_id' => ['nullable', 'integer'],
            'discount_type' => ['required', Rule::enum(SalesOrderDiscountType::class)],
            'discount_value' => ['nullable', 'decimal:0,4', 'gte:0'],
        ];
    }
}
