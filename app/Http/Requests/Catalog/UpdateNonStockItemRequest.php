<?php

namespace App\Http\Requests\Catalog;

use App\Enums\PriceInputMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNonStockItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('nonStockItem')) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'unit_label' => ['sometimes', 'nullable', 'string', 'max:64'],
            'price_input_mode' => ['sometimes', Rule::enum(PriceInputMode::class)],
            'unit_price' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'tax_rate_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
