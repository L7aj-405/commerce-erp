<?php

namespace App\Http\Requests\Documents;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;

class CreateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Invoice::class, $this->route('order')]) ?? false;
    }

    public function rules(): array
    {
        return ['invoice_date' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:5000']];
    }
}
