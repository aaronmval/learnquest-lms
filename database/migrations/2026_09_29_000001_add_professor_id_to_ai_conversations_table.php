<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            // A conversation belongs to either a student (QuestAI tutor) or a
            // professor (QuestAI Coach); exactly one of the two is set.
            $table->foreignId('student_id')->nullable()->change();
            $table->foreignId('professor_id')->nullable()->after('student_id')
                ->constrained('users')->cascadeOnDelete();

            $table->index(['professor_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropIndex(['professor_id', 'last_message_at']);
            $table->dropConstrainedForeignId('professor_id');
        });
    }
};
