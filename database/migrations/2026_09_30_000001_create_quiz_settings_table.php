<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Teacher-configured quiz settings per lesson (Quiz & AI Setup page).
        Schema::create('quiz_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_post_id')->unique()->constrained('class_posts')->cascadeOnDelete();
            // Who last saved these settings — their latest settings pre-fill
            // the next lesson they configure.
            $table->foreignId('professor_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('question_count');
            $table->unsignedSmallInteger('time_limit_minutes')->nullable(); // null = no timer
            $table->json('difficulty_mix'); // {"easy": 30, "medium": 40, "hard": 30} — percents
            $table->unsignedTinyInteger('max_attempts')->nullable();        // null = unlimited
            $table->boolean('shuffle_questions')->default(true);
            $table->boolean('shuffle_choices')->default(false);
            $table->boolean('show_answers')->default(true);
            $table->timestamps();

            $table->index(['professor_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_settings');
    }
};
