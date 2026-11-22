<?php

namespace App\Http\Requests\Finance;

use App\Enums\SupplierInvoiceVoidReason;
use App\Services\ActiveTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Void payload. Permission-only here; the action enforces store access and handles an already-void invoice idempotently. */
class VoidSupplierInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = app(ActiveTenantContext::class)->organization();

        return $organization !== null && $this->user()->hasPermission($organization, 'supplier_invoices.void');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason_code' => ['required', Rule::enum(SupplierInvoiceVoidReason::class)],
            'reason' => ['nullable', 'string', 'max:2000', Rule::requiredIf(fn () => $this->input('reason_code') === SupplierInvoiceVoidReason::Other->value)],
        ];
    }
}
