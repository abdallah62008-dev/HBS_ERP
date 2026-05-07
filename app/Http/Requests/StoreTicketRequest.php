<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            // status is admin-set only — controller drops it for non-admin
            // submitters and forces 'open'. Validating here for sanity.
            'status' => ['nullable', Rule::in(Ticket::STATUSES)],
        ];
    }
}
