<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Address / contact may need to change after creation.
            'customer_address' => ['sometimes', 'required', 'string'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'governorate' => ['nullable', 'string', 'max:255'],
            'country' => ['sometimes', 'required', 'string', 'max:255'],

            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'source' => ['nullable', 'string', 'max:64'],

            // Money tweaks. Item-level edits go through their own endpoint
            // because they require a recalculation cascade.
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'extra_fees' => ['nullable', 'numeric', 'min:0'],

            // Status changes go through OrdersController@changeStatus
            // (separate endpoint), but allow it here as a convenience for
            // simple admin overrides. The model defines the enum list.
            'status' => ['nullable', Rule::in(Order::STATUSES)],
            'status_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
