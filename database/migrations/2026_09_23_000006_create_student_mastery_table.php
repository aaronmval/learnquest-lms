<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_mastery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained('competencies')->cascadeOnDelete();
            $table->decimal('initial_mastery', 5, 4);
            $table->decimal('current_mastery', 5, 4);
            $table->decimal('p_l0', 5, 4);
            $table->decimal('p_t', 5, 4);
            $table->decimal('p_g', 5, 4);
            $table->decimal('p_s', 5, 4);
            $table->unsignedInteger('observations_count')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->unsignedInteger('incorrect_count')->default(0);
            $table->string('last_response', 10)->nullable();
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'competency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_mastery');
    }
};
