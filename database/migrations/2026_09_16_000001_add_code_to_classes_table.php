<?php

use App\Models\ClassRoom;
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
        Schema::table('classes', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('room');
        });

        // Backfill any existing rows (there is no code generator yet at the
        // database layer, so do it here the same way the model will).
        ClassRoom::whereNull('code')->get()->each(function (ClassRoom $class) {
            $class->code = ClassRoom::generateUniqueCode($class->subject ?: $class->name);
            $class->save();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
