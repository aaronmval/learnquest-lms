<?php

namespace App\Http\Requests;

use App\Models\QuizQuestionReview;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewQuizQuestionRequest extends FormRequest
{
    /**
     * Route middleware (auth + professor.role) plus the controller's
     * class-ownership check restrict this to the class's professor.
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
            'verdict' => ['required', Rule::in([QuizQuestionReview::APPROVED, QuizQuestionReview::REJECTED])],
            'reason' => ['nullable', Rule::in(QuizQuestionReview::REASONS)],
            'comment' => ['nullable', 'string', 'max:500'],
            'teacher_difficulty' => ['nullable', Rule::in(['easy', 'medium', 'hard'])],
        ];
    }
}
