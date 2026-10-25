<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:pdf,docx,xlsx',
                'max:'.intdiv((int) config('card.guardrails.max_upload_bytes'), 1024),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Поддерживаются только pdf, docx и xlsx',
            'file.max' => 'Файл больше допустимого размера',
        ];
    }
}
