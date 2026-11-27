<?php

namespace App\Http\Requests\Finance;

use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\Gate;

class StoreSupplierInvoiceRequest extends SaveSupplierInvoiceRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('create', SupplierInvoice::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + ['client_operation_id' => ['nullable', 'uuid']];
    }
}
