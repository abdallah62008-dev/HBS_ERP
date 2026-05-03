<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $marketer = $this->route('marketer');
        $id = is_object($marketer) ? $marketer->id : $marketer;

        return [
            // For create: existing user OR inline user creation
            'user_id' => ['nullable', 'exists:users,id'],
            'user' => ['nullable', 'array'],
            'user.name' => ['required_without:user_id', 'nullable', 'string', 'max:255'],
            'user.email' => ['required_without:user_id', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'user.password' => ['required_without:user_id', 'nullable', 'string', 'min:8'],

            'code' => ['required', 'string', 'max:32', Rule::unique('marketers', 'code')->ignore($id)],
            'price_group_id' => ['required', 'exists:marketer_price_groups,id'],
            'phone' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'in:Active,Inactive,Suspended'],
            'shipping_deducted' => ['nullable', 'boolean'],
            'tax_deducted' => ['nullable', 'boolean'],
            'commission_after_delivery_only' => ['nullable', 'boolean'],
            'settlement_cycle' => ['nullable', 'in:Daily,Weekly,Monthly'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
