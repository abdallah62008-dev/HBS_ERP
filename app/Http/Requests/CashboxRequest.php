<?php

namespace App\Http\Requests;

use App\Models\Cashbox;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for create & edit. On edit (`$cashbox` route param)
 * opening_balance and currency_code are stripped — they are immutable
 * after the cashbox has any transactions (and currency_code is fixed
 * at creation regardless). The service layer is the final authority;
 * this request enforces the same rules at the HTTP boundary.
 */
class CashboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $cashboxId = $this->route('cashbox')?->id ?? null;

        $rules = [
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('cashboxes', 'name')->ignore($cashboxId),
            ],
            'type' => ['required', 'string', Rule::in(Cashbox::TYPES)],
            'allow_negative_balance' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'description' => ['nullable', 'string'],
        ];

        if (! $isUpdate) {
            $rules['currency_code'] = ['required', 'string', 'max:8'];
            $rules['opening_balance'] = ['required', 'numeric'];
        }

        return $rules;
    }
}
