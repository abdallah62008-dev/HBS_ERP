<?php

namespace App\Http\Requests;

use App\Models\AdCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(AdCampaign::PLATFORMS)],
            'product_id' => ['nullable', 'exists:products,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'spend' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:Active,Paused,Ended'],
        ];
    }
}
