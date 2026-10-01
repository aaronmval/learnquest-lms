<?php

namespace App\Http\Requests;

use App\Services\AI\SlideDeckGenerationService;
use App\Services\Documents\PresentationBuilderService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateSlideDeckRequest extends FormRequest
{
    /**
     * Route middleware (auth + professor.role) restricts this endpoint.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Either an uploaded PDF or one of the professor's existing modules.
            'file' => ['nullable', 'required_without:module_id', 'file', 'mimes:pdf', 'max:25600'],
            'module_id' => ['nullable', 'integer', 'exists:modules,id'],
            // Optional: tailor the deck to this class's weak competencies.
            'focus_class_id' => ['nullable', 'integer'],
            'slide_count' => ['required', 'integer', Rule::in(SlideDeckGenerationService::SLIDE_COUNTS)],
            'theme' => ['required', 'string', Rule::in(array_keys(PresentationBuilderService::THEMES))],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required_without' => 'Upload a PDF or choose one of your modules.',
            'file.mimes' => 'Please upload a PDF file.',
            'file.max' => 'The PDF must be 25MB or smaller.',
        ];
    }
}
