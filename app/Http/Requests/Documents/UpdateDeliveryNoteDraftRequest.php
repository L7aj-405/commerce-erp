<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeliveryNoteDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateDraft', $this->route('deliveryNote')) ?? false;
    }

    public function rules(): array
    {
        return [
            'delivery_date' => ['required', 'date_format:Y-m-d'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_company' => ['nullable', 'string', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:64'],
            'delivery_address' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
