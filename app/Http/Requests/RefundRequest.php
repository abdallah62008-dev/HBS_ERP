<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for refund create + edit (Phase 5A — paperwork only).
 *
 * `amount` is required. Linkage columns (order_id, collection_id,
 * order_return_id, customer_id) are optional individually — a refund
 * can be a goodwill standalone gesture or attached to one or more
 * domain objects.
 *
 * Phase 5A intentionally does NOT validate cashbox_id or
 * payment_method_id — those are Phase 5B fields, not yet exposed.
 *
 * The route-bound `$refund` is rejected by the controller (not the
 * request) when not in `requested` state. Keeping that logic in the
 * controller makes the error surface as a flash error rather than a
 * 422 — operationally clearer for users.
 */
class RefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'collection_id' => ['nullable', 'integer', 'exists:collections,id'],
            'order_return_id' => ['nullable', 'integer', 'exists:returns,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ];
    }
}
