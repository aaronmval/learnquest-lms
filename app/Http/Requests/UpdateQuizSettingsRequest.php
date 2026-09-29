<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateQuizSettingsRequest extends FormRequest
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
            'question_count' => ['required', 'integer', 'min:'.config('quiz.min_question_count'), 'max:'.config('quiz.max_question_count')],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:'.config('quiz.max_time_limit_minutes')],
            'difficulty_mix' => ['required', 'array'],
            'difficulty_mix.easy' => ['required', 'integer', 'min:0', 'max:100'],
            'difficulty_mix.medium' => ['required', 'integer', 'min:0', 'max:100'],
            'difficulty_mix.hard' => ['required', 'integer', 'min:0', 'max:100'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'shuffle_questions' => ['required', 'boolean'],
            'shuffle_choices' => ['required', 'boolean'],
            'show_answers' => ['required', 'boolean'],
            'adaptive' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $mix = (array) $this->input('difficulty_mix', []);
                $sum = (int) ($mix['easy'] ?? 0) + (int) ($mix['medium'] ?? 0) + (int) ($mix['hard'] ?? 0);

                if (! $validator->errors()->has('difficulty_mix.*') && $sum !== 100) {
                    $validator->errors()->add('difficulty_mix', 'The difficulty mix must add up to 100%.');
                }
            },
        ];
    }
}
