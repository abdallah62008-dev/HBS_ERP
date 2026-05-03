<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarketerPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'trade_price' => ['required', 'numeric', 'min:0'],
            'minimum_selling_price' => ['required', 'numeric', 'min:0', 'gte:trade_price'],
        ];
    }
}
