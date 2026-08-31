<?php

namespace App\Http\Requests\Payments;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordSalesOrderPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_operation_id' => ['required', 'uuid'],
            'payments' => ['required', 'array', 'min:1', 'max:10'],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payments.*.financial_account_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'decimal:0,4', 'gt:0'],
            'payments.*.payment_date' => ['required', 'date_format:Y-m-d'],
            'payments.*.reference' => ['nullable', 'string', 'max:255'],
            'payments.*.external_reference' => ['nullable', 'string', 'max:255'],
            'payments.*.notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
