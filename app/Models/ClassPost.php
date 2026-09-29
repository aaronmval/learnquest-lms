<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClassPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_id',
        'author_id',
        'module_id',
        'type',
        'quarter',
        'title',
        'body',
        'checklist',
        'attachment_path',
        'attachment_name',
        'edited',
    ];

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'edited' => 'boolean',
        ];
    }

    public function classRoom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'class_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_id');
    }

    public function summary(): HasOne
    {
        return $this->hasOne(LessonSummary::class);
    }

    /**
     * The lesson's current AI-generated quiz — quizzes are versioned
     * (archived_at IS NULL means "current"; regenerating archives the old
     * version instead of deleting it, so past attempts/answers survive).
     */
    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class)->whereNull('archived_at');
    }

    /** Teacher-configured quiz settings (Quiz & AI Setup); absent until saved. */
    public function quizSetting(): HasOne
    {
        return $this->hasOne(QuizSetting::class);
    }
}
