<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->string('quarter', 40)->nullable()->after('description');
        });

        // Modules uploaded before this column existed only recorded their
        // quarter on the class posts they created — copy it back from there.
        DB::table('class_posts')
            ->whereNotNull('module_id')
            ->whereNotNull('quarter')
            ->orderBy('id')
            ->get(['module_id', 'quarter'])
            ->unique('module_id')
            ->each(function ($post) {
                DB::table('modules')->where('id', $post->module_id)->update(['quarter' => $post->quarter]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn('quarter');
        });
    }
};
