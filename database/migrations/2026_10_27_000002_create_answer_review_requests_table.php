<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A student asking the teacher for permission to see the correct
        // answers and explanations of a submitted attempt (lessons where
        // the teacher doesn't show answers right after submitting).
        Schema::create('answer_review_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->unique()->constrained('quiz_attempts')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('class_post_id')->constrained('class_posts')->cascadeOnDelete();
            $table->string('status', 10)->default('pending'); // pending | approved | denied
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['class_post_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_review_requests');
    }
};
