<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('class_posts', function (Blueprint $table) {
            $table->foreignId('module_id')->nullable()->after('class_id')
                ->constrained('modules')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('module_id');
        });
    }
};
