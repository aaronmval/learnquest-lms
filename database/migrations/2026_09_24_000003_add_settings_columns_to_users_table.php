<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Private path on the local disk; served only through /settings/avatar.
            $table->string('avatar_path')->nullable()->after('role');
            // Null = every System Alert enabled (see User::wantsAlert()).
            $table->json('notification_preferences')->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'notification_preferences']);
        });
    }
};
