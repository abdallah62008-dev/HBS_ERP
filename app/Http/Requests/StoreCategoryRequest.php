<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->where(
                    fn ($q) => $q->where('parent_id', $this->input('parent_id'))
                ),
            ],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'status' => ['nullable', 'in:Active,Inactive'],
        ];
    }
}
