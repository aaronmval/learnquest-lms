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
        'avatar_path',
        'notification_preferences',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'avatar_path',
    ];

    /**
     * System Alert preference keys a user can toggle, per role. Anything not
     * stored defaults to enabled.
     */
    public const ALERT_PREFERENCES = [
        'student' => ['announcements', 'lessons', 'mastery', 'sound'],
        'professor' => ['enrollment', 'at_risk', 'quiz_feedback', 'sound'],
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
            'notification_preferences' => 'array',
        ];
    }

    /**
     * Whether this user wants the given System Alert (or in-app sound).
     * Unset preferences default to on.
     */
    public function wantsAlert(string $key): bool
    {
        return (bool) (($this->notification_preferences ?? [])[$key] ?? true);
    }

    /**
     * The preference keys that apply to this user's role, with current values.
     *
     * @return array<string, bool>
     */
    public function alertPreferences(): array
    {
        $keys = self::ALERT_PREFERENCES[$this->role] ?? [];

        return collect($keys)->mapWithKeys(fn ($key) => [$key => $this->wantsAlert($key)])->all();
    }

    /**
     * URL of the user's own profile photo, cache-busted on change; null if none.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path
            ? route('settings.avatar.show', ['v' => $this->updated_at?->timestamp])
            : null;
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

    /**
     * This user's quiz attempts (student role).
     */
    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class, 'student_id');
    }

    /**
     * This user's per-competency BKT mastery records (student role).
     */
    public function masteryRecords(): HasMany
    {
        return $this->hasMany(StudentMastery::class, 'student_id');
    }
}
