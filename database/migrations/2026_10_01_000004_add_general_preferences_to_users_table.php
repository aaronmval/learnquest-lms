<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * General (display and behaviour) settings chosen on the Settings page.
     * Null means the user has not changed any of them.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('general_preferences')->nullable()->after('notification_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('general_preferences');
        });
    }
};
