<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Teacher toggle: pick each student's questions by their BKT mastery.
        Schema::table('quiz_settings', function (Blueprint $table) {
            $table->boolean('adaptive')->default(true)->after('show_answers');
        });

        // The questions served to the student in this attempt and the BKT
        // mastery level that chose them — so reloads show the same set,
        // grading covers only what was served, and each adaptive decision
        // stays traceable.
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->json('question_ids')->nullable()->after('deadline_at');
            $table->string('mastery_level', 12)->nullable()->after('question_ids');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn(['question_ids', 'mastery_level']);
        });

        Schema::table('quiz_settings', function (Blueprint $table) {
            $table->dropColumn('adaptive');
        });
    }
};
