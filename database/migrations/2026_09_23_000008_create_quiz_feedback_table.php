<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->unique()->constrained('quiz_attempts')->cascadeOnDelete();
            // Denormalized copy of quiz_attempts.quiz_id (mirrors how
            // quiz_answers.competency_id is already denormalized), so
            // aggregation queries never need to join through attempts.
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('difficulty', 20);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['quiz_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_feedback');
    }
};
