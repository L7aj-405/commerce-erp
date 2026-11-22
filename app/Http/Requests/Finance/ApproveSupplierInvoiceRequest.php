<?php

namespace App\Http\Requests\Finance;

use App\Services\ActiveTenantContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Approval payload. Authorization here is permission-only: the action enforces store access and handles an
 * already-approved invoice idempotently (a retried request must not turn into a 403).
 */
class ApproveSupplierInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = app(ActiveTenantContext::class)->organization();

        return $organization !== null && $this->user()->hasPermission($organization, 'supplier_invoices.approve');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'control_total_ttc' => ['required', 'decimal:0,4'],
            'acknowledge_unmatched' => ['nullable', 'boolean'],
            'match_note' => ['nullable', 'string', 'max:2000'],
            'acknowledged_warnings' => ['nullable', 'array', 'max:20'],
            'acknowledged_warnings.*' => ['string', 'max:64'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $control = $this->input('control_total_ttc');
        if (is_int($control) || is_float($control)) {
            $this->merge(['control_total_ttc' => (string) $control]);
        }
    }
}
