<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $table->foreignId('quiz_question_id')->constrained('quiz_questions')->cascadeOnDelete();
            // Denormalized copy of quiz_questions.competency_id, so BKT/analytics
            // queries over responses don't need a join through quiz_questions.
            $table->foreignId('competency_id')->constrained('competencies');
            $table->unsignedTinyInteger('selected_index')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->timestamp('answered_at');
            $table->timestamps();

            $table->unique(['quiz_attempt_id', 'quiz_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_answers');
    }
};
