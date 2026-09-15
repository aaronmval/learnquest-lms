<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function professor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professor_id');
    }
}
