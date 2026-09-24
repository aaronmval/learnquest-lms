<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentMastery extends Model
{
    protected $table = 'student_mastery';

    protected $fillable = [
        'student_id',
        'competency_id',
        'initial_mastery',
        'current_mastery',
        'p_l0',
        'p_t',
        'p_g',
        'p_s',
        'observations_count',
        'correct_count',
        'incorrect_count',
        'last_response',
        'last_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'initial_mastery' => 'float',
            'current_mastery' => 'float',
            'p_l0' => 'float',
            'p_t' => 'float',
            'p_g' => 'float',
            'p_s' => 'float',
            'last_updated_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }
}
