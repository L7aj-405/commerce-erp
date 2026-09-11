<?php

namespace App\Http\Requests\Quotations;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('quotation')) ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'nullable', 'integer'],
            'sync_customer_snapshot' => ['sometimes', 'boolean'],
            'quotation_date' => ['sometimes', 'date_format:Y-m-d'],
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'customer_tax_identifier' => ['sometimes', 'nullable', 'string', 'max:128'],
            'billing_address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'representative_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
