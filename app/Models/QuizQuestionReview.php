<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizQuestionReview extends Model
{
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const REASONS = ['incorrect_answer', 'off_topic', 'unclear', 'too_easy', 'too_hard', 'duplicate', 'other'];

    protected $fillable = [
        'quiz_question_id',
        'professor_id',
        'verdict',
        'reason',
        'comment',
        'ai_difficulty',
        'teacher_difficulty',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'quiz_question_id');
    }

    public function professor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professor_id');
    }
}
