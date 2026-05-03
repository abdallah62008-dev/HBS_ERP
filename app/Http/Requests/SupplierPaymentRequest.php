<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['nullable', 'string', 'max:64'],
            'payment_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'attachment_url' => ['nullable', 'string', 'max:1024'],
            // When paying a specific invoice from the supplier page.
            'purchase_invoice_id' => ['nullable', 'exists:purchase_invoices,id'],
        ];
    }
}
