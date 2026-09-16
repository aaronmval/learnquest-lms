<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Module extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_id',
        'uploaded_by',
        'title',
        'description',
        'file_path',
        'file_name',
        'file_size',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The sections (classes) this module is targeted at. No rows means the
     * module is available to every section under its subject.
     */
    public function targetSections(): BelongsToMany
    {
        return $this->belongsToMany(ClassRoom::class, 'module_sections', 'module_id', 'class_id');
    }
}
