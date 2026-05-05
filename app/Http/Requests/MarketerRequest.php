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
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        // user.* fields are only meaningful on create (inline user creation
        // or pick existing). On update the user account is not editable
        // through this form, so the rules are skipped entirely.
        $userRules = $isUpdate ? [] : [
            'user_id' => ['nullable', 'exists:users,id'],
            'user' => ['nullable', 'array'],
            'user.name' => ['required_without:user_id', 'nullable', 'string', 'max:255'],
            'user.email' => ['required_without:user_id', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'user.password' => ['required_without:user_id', 'nullable', 'string', 'min:8'],
        ];

        return $userRules + [
            'code' => ['required', 'string', 'max:32', Rule::unique('marketers', 'code')->ignore($id)],
            'price_group_id' => ['required', 'exists:marketer_price_groups,id'],
            // Phase 5.7 — Pricing tier (A/B/D/E). Nullable for backward
            // compatibility with marketers created before tiers existed.
            // The Rule::exists where-clause restricts the FK to rows whose
            // code is set (i.e. Phase 5.6 tier rows), excluding the legacy
            // Bronze/Silver/Gold/VIP price groups.
            'marketer_price_tier_id' => [
                'nullable',
                Rule::exists('marketer_price_groups', 'id')
                    ->whereNotNull('code')
                    ->where('status', 'Active'),
            ],
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
