<?php

namespace App\Http\Requests\Catalog;

use App\Enums\PriceInputMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNonStockItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller authorizes against the active tenant context.
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'unit_label' => ['nullable', 'string', 'max:64'],
            'price_input_mode' => ['nullable', Rule::enum(PriceInputMode::class)],
            'unit_price' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'tax_rate_id' => ['nullable', 'integer'],
        ];
    }
}
