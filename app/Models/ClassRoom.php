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
    ];

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
