<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ClassRoom extends Model
{
    use HasFactory;

    /**
     * The database table is "classes" — the model can't be named Class since
     * that's a reserved word in PHP.
     */
    protected $table = 'classes';

    protected $fillable = [
        'professor_id',
        'name',
        'section',
        'subject',
        'room',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Note: "code" is intentionally NOT fillable — it's always generated
     * server-side (see booted() below), never accepted from request input.
     */
    protected static function booted(): void
    {
        static::creating(function (ClassRoom $class) {
            if (! $class->code) {
                $class->code = static::generateUniqueCode($class->subject ?: $class->name);
            }
        });
    }

    public function professor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professor_id');
    }

    /**
     * The subject this section belongs to, if it was created from the
     * Modules page rather than the standalone Classes flow. Named
     * parentSubject() rather than subject() because "subject" is already a
     * plain string column on this table (the free-text subject label used
     * by the standalone Classes flow) — a same-named relation method would
     * be shadowed by that column on magic property access ($class->subject).
     */
    public function parentSubject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'class_enrollments', 'class_id', 'student_id')
            ->withTimestamps();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(ClassPost::class, 'class_id');
    }

    /**
     * Whether the given user manages this section — either directly (the
     * professor who created it) or through the subject it belongs to (a
     * subject collaborator manages every section under that subject).
     */
    public function isManagedBy(User $user): bool
    {
        if ($this->professor_id === $user->id) {
            return true;
        }

        return $this->subject_id !== null && $this->parentSubject && $this->parentSubject->isManagedBy($user);
    }

    /**
     * Assign a fresh invite code, replacing whatever the class currently has.
     */
    public function regenerateCode(): string
    {
        $this->code = static::generateUniqueCode($this->subject ?: $this->name);
        $this->save();

        return $this->code;
    }

    /**
     * Build a unique invite code shaped like "SUBJ-9F3KRT" — a short,
     * human-shareable prefix from the class's subject/name, plus a random
     * alphanumeric suffix, retried until it doesn't collide.
     */
    public static function generateUniqueCode(?string $seed = null): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $seed));
        $prefix = substr($prefix, 0, 4) ?: 'CLASS';

        do {
            $candidate = $prefix.'-'.strtoupper(Str::random(6));
        } while (static::where('code', $candidate)->exists());

        return $candidate;
    }
}
