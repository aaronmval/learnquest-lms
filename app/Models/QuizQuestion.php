<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class QuizQuestion extends Model
{
    protected $fillable = [
        'quiz_id',
        'competency_id',
        'ai_competency_id',
        'question_text',
        'choices',
        'correct_answer',
        'explanation',
        'difficulty',
        'order_index',
    ];

    protected function casts(): array
    {
        return [
            'choices' => 'array',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    /** The AI's original tag, set only after a teacher corrected it. */
    public function aiCompetency(): BelongsTo
    {
        return $this->belongsTo(Competency::class, 'ai_competency_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(QuizQuestionReview::class);
    }

    /**
     * Questions students can see and be graded on: everything except ones
     * a teacher rejected. Rejected rows are kept so past attempts and the
     * AI-training data stay intact.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereDoesntHave('review', fn (Builder $q) => $q->where('verdict', QuizQuestionReview::REJECTED));
    }
}
