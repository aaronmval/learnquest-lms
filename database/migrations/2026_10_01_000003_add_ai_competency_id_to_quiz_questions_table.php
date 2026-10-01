<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The competency the model originally tagged the question with, kept
        // only once a teacher corrects the tag — so the correction stays
        // traceable. Null means the tag is still the AI's own.
        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->foreignId('ai_competency_id')->nullable()->after('competency_id')
                ->constrained('competencies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_competency_id');
        });
    }
};
