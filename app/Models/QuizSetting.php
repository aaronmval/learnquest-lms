<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizSetting extends Model
{
    protected $fillable = [
        'class_post_id',
        'professor_id',
        'question_count',
        'time_limit_minutes',
        'difficulty_mix',
        'max_attempts',
        'shuffle_questions',
        'shuffle_choices',
        'show_answers',
        'adaptive',
    ];

    protected function casts(): array
    {
        return [
            'question_count' => 'integer',
            'time_limit_minutes' => 'integer',
            'difficulty_mix' => 'array',
            'max_attempts' => 'integer',
            'shuffle_questions' => 'boolean',
            'shuffle_choices' => 'boolean',
            'show_answers' => 'boolean',
            'adaptive' => 'boolean',
        ];
    }

    public function classPost(): BelongsTo
    {
        return $this->belongsTo(ClassPost::class);
    }
}
