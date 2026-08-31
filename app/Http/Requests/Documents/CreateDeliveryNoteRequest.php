<?php

namespace App\Http\Requests\Documents;

use App\Models\DeliveryNote;
use Illuminate\Foundation\Http\FormRequest;

class CreateDeliveryNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [DeliveryNote::class, $this->route('order')]) ?? false;
    }

    public function rules(): array
    {
        return ['delivery_date' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:5000']];
    }
}
