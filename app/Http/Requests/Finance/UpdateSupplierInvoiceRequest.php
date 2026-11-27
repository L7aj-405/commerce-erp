<?php

namespace App\Http\Requests\Finance;

use Illuminate\Support\Facades\Gate;

class UpdateSupplierInvoiceRequest extends SaveSupplierInvoiceRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('update', $this->route('supplierInvoice'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + ['row_version' => ['required', 'integer', 'min:1']];
    }
}
