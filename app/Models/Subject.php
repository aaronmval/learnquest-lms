<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    public function competencies(): HasMany
    {
        return $this->hasMany(Competency::class);
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

    /**
     * The active classes the given user may post this subject's modules
     * into. The owner can use every section of the subject; a collaborator
     * only the sections they created. Either can also use their own
     * standalone classes (which get linked to this subject when targeted),
     * but never a class that belongs to a different subject.
     */
    public function targetableSectionsFor(User $user): Builder
    {
        $isOwner = $this->owner_id === $user->id;

        return ClassRoom::query()
            ->whereNull('archived_at')
            ->where(function (Builder $query) use ($user, $isOwner) {
                $query->where(fn (Builder $q) => $q
                    ->where('professor_id', $user->id)
                    ->where(fn (Builder $own) => $own->where('subject_id', $this->id)->orWhereNull('subject_id')));

                if ($isOwner) {
                    $query->orWhere('subject_id', $this->id);
                }
            });
    }
}
