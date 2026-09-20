<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonSummary extends Model
{
    protected $fillable = [
        'class_post_id',
        'overview',
        'key_points',
        'model',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'key_points' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function classPost(): BelongsTo
    {
        return $this->belongsTo(ClassPost::class);
    }
}
