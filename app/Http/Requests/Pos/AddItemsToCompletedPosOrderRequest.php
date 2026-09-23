<?php

namespace App\Http\Requests\Pos;

use App\Models\SalesOrder;
use Illuminate\Foundation\Http\FormRequest;

class AddItemsToCompletedPosOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof SalesOrder
            && $this->user()?->can('completePos', $order) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_operation_id' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_variant_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'regex:/^[1-9]\d*(?:\.0{1,4})?$/', 'max:999999999999999'],
        ];
    }
}
