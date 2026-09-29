<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreQuestAiMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Shared by the student tutor and the professor coach: route role
        // middleware plus each controller's enrollment/management and
        // ownership checks restrict this to the user's own data.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
            'class_id' => ['nullable', 'integer'],
            'conversation_id' => ['nullable', 'integer'],
        ];
    }
}
