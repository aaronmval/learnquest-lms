<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A teacher's verdict on one AI-generated question. These rows are
        // the labeled data QuizTrainingService feeds back into generation
        // prompts (approved examples, rejection reasons, difficulty labels).
        Schema::create('quiz_question_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_question_id')->unique()->constrained('quiz_questions')->cascadeOnDelete();
            $table->foreignId('professor_id')->constrained('users')->cascadeOnDelete();
            $table->string('verdict', 10); // approved | rejected
            $table->string('reason', 30)->nullable();
            $table->text('comment')->nullable();
            // The model's original label, kept even after the teacher corrects
            // quiz_questions.difficulty, so AI-vs-teacher agreement stays measurable.
            $table->string('ai_difficulty', 10);
            $table->string('teacher_difficulty', 10)->nullable();
            $table->timestamps();

            $table->index(['verdict', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_question_reviews');
    }
};
