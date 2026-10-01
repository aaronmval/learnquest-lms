<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\AI\SlideDeckGenerationService;
use App\Services\Documents\PresentationBuilderService;
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
        'general_preferences',
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
            'general_preferences' => 'array',
        ];
    }

    /**
     * General (display and behaviour) settings a user of the given role can
     * change: each key's allowed values and its default. A null default
     * means "not chosen yet" (the browser keeps its current state).
     *
     * @return array<string, array{values: array<int, mixed>, default: mixed}>
     */
    public static function generalPreferenceOptions(string $role): array
    {
        $options = [
            'theme' => ['values' => ['light', 'dark', 'system'], 'default' => null],
            'motion' => ['values' => ['full', 'reduced'], 'default' => 'full'],
            'text_size' => ['values' => ['default', 'small', 'large'], 'default' => 'default'],
            'sidebar' => ['values' => ['remember', 'expanded', 'collapsed'], 'default' => 'remember'],
            'start_page' => ['values' => ['home', 'dashboard'], 'default' => 'home'],
            'restore_last_page' => ['values' => [true, false], 'default' => true],
            'chart_labels' => ['values' => [true, false], 'default' => true],
            // Privacy cover shown before the dashboard (a client-side cover, not access control).
            'dashboard_lock' => ['values' => [true, false], 'default' => true],
        ];

        if ($role === 'professor') {
            $options['deck_slide_count'] = ['values' => SlideDeckGenerationService::SLIDE_COUNTS, 'default' => 12];
            $options['deck_theme'] = ['values' => array_keys(PresentationBuilderService::THEMES), 'default' => 'learnquest'];
            // Set once the Quiz & AI Setup guided tour has been finished or skipped.
            $options['quiz_setup_tour_seen'] = ['values' => [true, false], 'default' => false];
        }

        return $options;
    }

    /**
     * This user's general settings, with defaults for anything not saved.
     *
     * @return array<string, mixed>
     */
    public function generalPreferences(): array
    {
        $stored = $this->general_preferences ?? [];

        return collect(self::generalPreferenceOptions($this->role))
            ->map(fn (array $option, string $key) => in_array($stored[$key] ?? null, $option['values'], true)
                ? $stored[$key]
                : $option['default'])
            ->all();
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
