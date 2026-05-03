<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $warehouse = $this->route('warehouse');
        $id = is_object($warehouse) ? $warehouse->id : $warehouse;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('warehouses', 'name')->ignore($id)],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:Active,Inactive'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
