<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The classes this user teaches (professor role).
     */
    public function classes(): HasMany
    {
        return $this->hasMany(ClassRoom::class, 'professor_id');
    }

    /**
     * The classes this user is enrolled in (student role).
     */
    public function enrolledClasses(): BelongsToMany
    {
        return $this->belongsToMany(ClassRoom::class, 'class_enrollments', 'student_id', 'class_id')
            ->withTimestamps();
    }

    /**
     * The subjects this user owns (professor role).
     */
    public function ownedSubjects(): HasMany
    {
        return $this->hasMany(Subject::class, 'owner_id');
    }

    /**
     * The subjects this user collaborates on (invited by another professor).
     */
    public function collaboratingSubjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'subject_collaborators', 'user_id', 'subject_id')
            ->withTimestamps();
    }
}
