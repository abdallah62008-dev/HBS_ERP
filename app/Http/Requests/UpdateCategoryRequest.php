<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $category = $this->route('category');
        $categoryId = is_object($category) ? $category->id : $category;

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('categories', 'name')
                    ->where(fn ($q) => $q->where('parent_id', $this->input('parent_id')))
                    ->ignore($categoryId),
            ],
            'parent_id' => [
                'nullable', 'exists:categories,id',
                // Prevent a category from being its own parent.
                Rule::notIn([$categoryId]),
            ],
            'status' => ['nullable', 'in:Active,Inactive'],
        ];
    }
}
