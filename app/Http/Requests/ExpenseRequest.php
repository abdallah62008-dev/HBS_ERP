<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
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
        ];
    }
}
