<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role', 20); // user | assistant
            $table->text('content');
            $table->string('model')->nullable();
            // Lesson posts whose content grounded this reply, for traceability.
            $table->json('source_post_ids')->nullable();
            // 1 = helpful, -1 = not helpful, null = no feedback given.
            $table->tinyInteger('feedback')->nullable();
            $table->timestamps();

            $table->index(['ai_conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
