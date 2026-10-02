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
        'quarter',
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
     * Whether the user may edit or delete this module: the owner of its
     * subject, or whoever uploaded it. Other collaborators ask the owner.
     */
    public function canBeModifiedBy(User $user, Subject $subject): bool
    {
        return $subject->owner_id === $user->id || $this->uploaded_by === $user->id;
    }

    /**
     * The sections (classes) this module is posted to. No rows means it is
     * not assigned to any section yet (it only lives in the subject library).
     */
    public function targetSections(): BelongsToMany
    {
        return $this->belongsToMany(ClassRoom::class, 'module_sections', 'module_id', 'class_id');
    }
}
