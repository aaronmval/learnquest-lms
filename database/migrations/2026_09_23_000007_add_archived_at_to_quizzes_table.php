<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turns quizzes into versioned records: a lesson can have multiple
     * Quiz rows over time (regenerated versions), but only one "current"
     * (archived_at IS NULL) at once. Dropping the unique constraint lets a
     * new version be inserted without ever deleting/cascading away past
     * QuizAttempt/QuizAnswer history tied to older versions.
     */
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('generated_at');
            // Added before dropping the unique index below — MySQL requires
            // class_post_id to always have *some* supporting index while its
            // foreign key constraint exists.
            $table->index(['class_post_id', 'archived_at']);
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropUnique(['class_post_id']);
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->unique('class_post_id');
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropIndex(['class_post_id', 'archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
