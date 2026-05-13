<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Expense validation rules.
 *
 * Phase 4 addition:
 *   - On CREATE: `cashbox_id` and `payment_method_id` are REQUIRED. The
 *     existing expense module has no payment lifecycle, so every new
 *     expense is treated as paid immediately — and must therefore land
 *     in a real cashbox.
 *   - On UPDATE of an unposted expense: both are nullable. (Posted
 *     expenses have their financial fields stripped server-side by the
 *     controller.)
 *
 * The legacy free-text `payment_method` column remains nullable so old
 * data continues to load. New UI submits the structured ID.
 */
class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        $rules = [
            'expense_category_id' => ['required', 'exists:expense_categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency_code' => ['required', 'string', 'max:8'],
            'expense_date' => ['required', 'date'],
            'payment_method' => ['nullable', 'string', 'max:64'],
            'related_order_id' => ['nullable', 'exists:orders,id'],
            'related_campaign_id' => ['nullable', 'exists:ad_campaigns,id'],
            'notes' => ['nullable', 'string'],
            'attachment_url' => ['nullable', 'string', 'max:1024'],
            // Phase 4.
            'cashbox_id' => [$isUpdate ? 'nullable' : 'required', 'integer', 'exists:cashboxes,id'],
            'payment_method_id' => [$isUpdate ? 'nullable' : 'required', 'integer', 'exists:payment_methods,id'],
        ];

        return $rules;
    }
}
