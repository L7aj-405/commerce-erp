<?php

namespace App\Http\Requests\Quotations;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller authorizes against the active tenant context.
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer'],
            'quotation_date' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_company' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email:rfc', 'max:254'],
            'customer_phone' => ['nullable', 'string', 'max:64'],
            'customer_tax_identifier' => ['nullable', 'string', 'max:128'],
            'billing_address' => ['nullable', 'string', 'max:2000'],
            'representative_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
