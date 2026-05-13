<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for payment method create + edit.
 *
 * `code` is unique and lower_snake_case (regex-enforced). The route-bound
 * model is excluded from uniqueness checks on update.
 */
class PaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $paymentMethodId = $this->route('paymentMethod')?->id ?? null;

        return [
            'name' => ['required', 'string', 'max:60'],
            'code' => [
                'required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('payment_methods', 'code')->ignore($paymentMethodId),
            ],
            'type' => ['required', 'string', Rule::in(PaymentMethod::TYPES)],
            'default_cashbox_id' => ['nullable', 'integer', 'exists:cashboxes,id'],
            'is_active' => ['required', 'boolean'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Code must be lowercase letters, digits, and underscores only.',
        ];
    }
}
