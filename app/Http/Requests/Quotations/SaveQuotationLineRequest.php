<?php

namespace App\Http\Requests\Quotations;

use App\Enums\PriceInputMode;
use App\Enums\QuotationLineType;
use App\Enums\SalesOrderDiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveQuotationLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('quotation')) ?? false;
    }

    public function rules(): array
    {
        return [
            'line_type' => ['required', Rule::enum(QuotationLineType::class)],

            // catalog
            'product_variant_id' => ['nullable', 'required_if:line_type,catalog', 'integer'],

            // non_stock — either an existing library item, or a brand-new article
            'non_stock_item_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'required_if:line_type,non_stock', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'unit_label' => ['nullable', 'string', 'max:64'],
            'tax_rate_id' => ['nullable', 'integer'],

            // price entry
            'price_input_mode' => ['nullable', Rule::enum(PriceInputMode::class)],
            'unit_price' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],

            'quantity' => ['required', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'discount_type' => ['required', Rule::enum(SalesOrderDiscountType::class)],
            'discount_value' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/', 'required_unless:discount_type,none'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('non_stock_item_id')) {
            $this->merge(['name' => $this->input('name') ?: 'article']);
        }
    }
}
