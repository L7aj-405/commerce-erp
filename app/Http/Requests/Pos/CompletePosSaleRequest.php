<?php

namespace App\Http\Requests\Pos;

use App\Enums\SalesOrderDiscountType;
use App\Enums\SalesOrderLineType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompletePosSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_operation_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.line_type' => ['required', Rule::enum(SalesOrderLineType::class)],
            'lines.*.product_variant_id' => ['nullable', 'required_if:lines.*.line_type,catalog', 'integer'],
            'lines.*.description' => ['nullable', 'required_if:lines.*.line_type,custom', 'string', 'max:255'],
            'lines.*.reference' => ['nullable', 'string', 'max:255'],
            'lines.*.unit_label' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.unit_price_excl_tax' => ['nullable', 'required_if:lines.*.line_type,custom', 'decimal:0,4', 'gte:0'],
            'lines.*.tax_rate_id' => ['nullable', 'integer'],
            'lines.*.discount_type' => ['required', Rule::enum(SalesOrderDiscountType::class)],
            'lines.*.discount_value' => ['nullable', 'decimal:0,4', 'gte:0'],
        ];
    }
}
