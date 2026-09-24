<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    protected $fillable = [
        'class_post_id',
        'model',
        'generated_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function classPost(): BelongsTo
    {
        return $this->belongsTo(ClassPost::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('order_index');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(QuizFeedback::class);
    }
}
