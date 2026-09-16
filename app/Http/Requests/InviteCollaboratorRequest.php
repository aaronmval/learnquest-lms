<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InviteCollaboratorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Route middleware (auth + professor.role) plus the controller's own
        // isManagedBy() check already restrict this to the subject's owner/collaborators.
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
            'email' => ['required', 'email', 'max:120'],
        ];
    }
}
