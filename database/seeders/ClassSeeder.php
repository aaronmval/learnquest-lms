<?php

namespace Database\Seeders;

use App\Models\ClassRoom;
use App\Models\User;
use Illuminate\Database\Seeder;

class ClassSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Backfills a "Chemistry" class for the first professor account found, so
     * environments that previously relied on the hardcoded frontend Chemistry
     * class keep an equivalent real database row. A no-op on a fresh install
     * with no professor account yet.
     */
    public function run(): void
    {
        $professor = User::where('role', 'professor')->first();

        if (! $professor) {
            return;
        }

        ClassRoom::firstOrCreate(
            ['professor_id' => $professor->id, 'name' => 'Chemistry'],
            ['section' => 'STEM - Amethyst', 'subject' => 'Chemistry', 'room' => null],
        );
    }
}
