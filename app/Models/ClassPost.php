<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
