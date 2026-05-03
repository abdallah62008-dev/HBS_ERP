<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachmentUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // 5MB cap. Images for photos, PDFs for invoices.
            'file' => ['required', 'file', 'max:5120', 'mimes:jpeg,jpg,png,webp,pdf'],
            'attachment_type' => ['required', 'string', 'max:64'],
        ];
    }
}
