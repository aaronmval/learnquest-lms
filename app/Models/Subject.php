<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'name',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'subject_collaborators', 'subject_id', 'user_id')
            ->withPivot('invited_by')
            ->withTimestamps();
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ClassRoom::class, 'subject_id');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class, 'subject_id');
    }

    /**
     * Whether the given user may manage this subject — its sections,
     * modules, and collaborator list. Collaborators are full co-owners,
     * short of deleting the subject or removing its original owner.
     */
    public function isManagedBy(User $user): bool
    {
        return $this->owner_id === $user->id
            || $this->collaborators()->where('user_id', $user->id)->exists();
    }
}
