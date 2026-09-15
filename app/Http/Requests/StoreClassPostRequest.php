<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClassPostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Route middleware (auth + professor.role) plus the controller's own
        // ownership check already restrict this to the class's professor.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:announcement,lesson'],
            'quarter' => ['required', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['nullable', 'string'],
            'checklist' => ['nullable', 'array'],
            'checklist.*' => ['string', 'max:200'],
            'attachment' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }
}
