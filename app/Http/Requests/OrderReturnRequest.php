<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'order_id' => ['required', 'exists:orders,id'],
            'return_reason_id' => ['required', 'exists:return_reasons,id'],
            'product_condition' => ['nullable', Rule::in(['Good', 'Damaged', 'Missing Parts', 'Unknown'])],
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_loss_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
