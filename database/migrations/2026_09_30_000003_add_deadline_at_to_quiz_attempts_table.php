<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Timed quizzes open an attempt (status "in_progress") when the
        // student starts, so the server knows the deadline and a page reload
        // doesn't reset the timer. Late submissions get status "late".
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->timestamp('deadline_at')->nullable()->after('started_at');
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropIndex(['student_id', 'status']);
            $table->dropColumn('deadline_at');
        });
    }
};
