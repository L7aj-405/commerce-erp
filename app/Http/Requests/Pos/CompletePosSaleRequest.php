<?php

namespace App\Http\Requests\Pos;

use App\Enums\PaymentMethod;
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
            'order_id' => ['nullable', 'integer', 'required_without:lines'],
            'warehouse_id' => ['nullable', 'integer', 'required_without:order_id'],
            'customer_id' => ['nullable', 'integer'],
            'payments' => ['required', 'array', 'min:1', 'max:10'],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payments.*.financial_account_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'decimal:0,4', 'gt:0'],
            'payments.*.cash_received' => ['nullable', 'required_if:payments.*.method,cash', 'decimal:0,4', 'gt:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:255'],
            'lines' => ['nullable', 'array', 'min:1', 'max:100', 'required_without:order_id'],
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
            'fulfillment_mode' => ['nullable', 'in:pickup,delivery'],
        ];
    }
}
