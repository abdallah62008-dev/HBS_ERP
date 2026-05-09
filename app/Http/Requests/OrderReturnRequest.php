<?php

namespace App\Http\Requests;

use App\Models\OrderReturn;
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
            'order_id' => [
                'required',
                'exists:orders,id',
                // One return per order. Backend gate even if the UI
                // dropdown bypass is attempted. The error string is
                // surfaced verbatim to Inertia's errors prop, then
                // rendered as a friendly message in the form.
                function ($attribute, $value, $fail) {
                    if (OrderReturn::where('order_id', $value)->exists()) {
                        $fail('This order already has a return record and cannot be returned again.');
                    }
                },
            ],
            'return_reason_id' => ['required', 'exists:return_reasons,id'],
            'product_condition' => ['nullable', Rule::in(['Good', 'Damaged', 'Missing Parts', 'Unknown'])],
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_loss_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
