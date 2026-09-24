<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            // Not cascadeOnDelete: a competency still referenced by questions
            // can't be deleted (see CompetencyController::destroy).
            $table->foreignId('competency_id')->constrained('competencies');
            $table->text('question_text');
            $table->json('choices');
            $table->string('correct_answer', 255);
            $table->text('explanation');
            $table->string('difficulty', 10);
            $table->unsignedTinyInteger('order_index')->default(0);
            $table->timestamps();

            $table->index(['quiz_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
    }
};
