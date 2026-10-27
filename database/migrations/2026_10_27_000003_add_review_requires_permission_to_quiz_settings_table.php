<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Teacher setting: students must ask permission before reviewing
        // their answers (which were right or wrong, and the answer key).
        // Existing lessons keep today's behaviour (false); new lessons use
        // the config default.
        Schema::table('quiz_settings', function (Blueprint $table) {
            $table->boolean('review_requires_permission')->default(false)->after('show_answers');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_settings', function (Blueprint $table) {
            $table->dropColumn('review_requires_permission');
        });
    }
};
