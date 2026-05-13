<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Finance Phase 5D — request rules for create / update of a marketer
 * payout in the `requested` lifecycle stage. Cashbox + payment method
 * are only required at pay-time, validated separately in the
 * controller's `pay()` action.
 */
class MarketerPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'marketer_id' => ['required', 'integer', 'exists:marketers,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
