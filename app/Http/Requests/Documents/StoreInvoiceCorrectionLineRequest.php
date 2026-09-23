<?php

namespace App\Http\Requests\Documents;

use App\Enums\SalesOrderDiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceCorrectionLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('editLines', $this->route('invoice')) ?? false;
    }

    public function rules(): array
    {
        return [
            'product_variant_id' => ['required', 'integer'],
            'quantity' => ['required', 'string', 'regex:/^[1-9]\d*(?:\.0{1,4})?$/'],
            'unit_price_excl_tax' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'discount_type' => ['required', Rule::enum(SalesOrderDiscountType::class)],
            'discount_value' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/', 'required_unless:discount_type,none'],
        ];
    }
}
