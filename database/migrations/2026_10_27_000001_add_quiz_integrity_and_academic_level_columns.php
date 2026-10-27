<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            // Times the student left the quiz (tab switch / alt-tab) while it
            // was in progress; reaching the configured limit auto-submits.
            $table->unsignedTinyInteger('focus_violations')->default(0)->after('mastery_level');
            // What chose mastery_level: "bkt" (quiz evidence) or "teacher"
            // (the teacher-set academic level, used before any evidence).
            $table->string('level_basis', 8)->nullable()->after('mastery_level');
        });

        // Teacher-set academic level per class: advanced / average /
        // needs_support. Only sets the starting quiz difficulty — BKT is
        // unaffected. Null = not set.
        Schema::table('class_enrollments', function (Blueprint $table) {
            $table->string('academic_level', 16)->nullable()->after('student_id');
        });
    }

    public function down(): void
    {
        Schema::table('class_enrollments', function (Blueprint $table) {
            $table->dropColumn('academic_level');
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn(['focus_violations', 'level_basis']);
        });
    }
};
