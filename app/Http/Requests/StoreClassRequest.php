<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClassRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Route middleware (auth + professor.role) already restricts this to professors.
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
            'name' => ['required', 'string', 'max:60'],
            'section' => ['nullable', 'string', 'max:60'],
            'subject' => ['nullable', 'string', 'max:60'],
            'room' => ['nullable', 'string', 'max:40'],
        ];
    }
}
