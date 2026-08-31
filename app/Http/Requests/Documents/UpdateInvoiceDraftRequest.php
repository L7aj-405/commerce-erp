<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateDraft', $this->route('invoice')) ?? false;
    }

    public function rules(): array
    {
        return [
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_company' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:64'],
            'customer_tax_identifier' => ['nullable', 'string', 'max:128'],
            'billing_address' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
