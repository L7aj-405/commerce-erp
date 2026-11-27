<?php

namespace App\Http\Requests\Finance;

use App\Enums\PaymentMethod;
use App\Enums\SupplierInvoiceCostNature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shared shape of the supplier invoice draft payload. Money and quantities are decimal strings. */
abstract class SaveSupplierInvoiceRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer'],
            'supplier_id' => ['required', 'integer'],
            'purchase_order_id' => ['nullable', 'integer'],
            'invoice_number' => ['required', 'string', 'max:64'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'currency_code' => ['required', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'rounding_adjustment' => ['nullable', 'decimal:0,4'],
            'control_total_ttc' => ['nullable', 'decimal:0,4'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.cost_nature' => ['required', Rule::enum(SupplierInvoiceCostNature::class)],
            'lines.*.product_variant_id' => ['nullable', 'integer'],
            'lines.*.purchase_order_line_id' => ['nullable', 'integer'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'decimal:0,4'],
            'lines.*.unit_price_ht' => ['required', 'decimal:0,4'],
            'lines.*.tax_rate_id' => ['required', 'integer'],
            'installments' => ['nullable', 'array', 'max:100'],
            'installments.*.due_date' => ['required', 'date_format:Y-m-d'],
            'installments.*.amount' => ['required', 'decimal:0,4'],
            'installments.*.planned_payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'installments.*.preferred_financial_account_id' => ['nullable', 'integer'],
        ];
    }

    /** JSON numbers may arrive as floats: turn them into their decimal text before validation (no arithmetic). */
    protected function prepareForValidation(): void
    {
        $stringify = fn (mixed $value) => is_float($value) || is_int($value) ? (string) $value : $value;
        $merge = [];
        foreach (['rounding_adjustment', 'control_total_ttc'] as $key) {
            if ($this->has($key)) {
                $merge[$key] = $stringify($this->input($key));
            }
        }
        foreach (['lines' => ['quantity', 'unit_price_ht'], 'installments' => ['amount']] as $group => $keys) {
            $rows = $this->input($group);
            if (is_array($rows)) {
                $merge[$group] = array_map(function ($row) use ($keys, $stringify) {
                    if (! is_array($row)) {
                        return $row;
                    }
                    foreach ($keys as $key) {
                        if (array_key_exists($key, $row)) {
                            $row[$key] = $stringify($row[$key]);
                        }
                    }

                    return $row;
                }, $rows);
            }
        }
        $this->merge($merge);
    }
}
