<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Standalone classes that already received module lessons have no
     * subject, so their quizzes had no competencies to use. Link each one
     * to the subject its module lessons come from — only when they all come
     * from a single subject.
     */
    public function up(): void
    {
        $subjectsByClass = DB::table('class_posts')
            ->join('modules', 'modules.id', '=', 'class_posts.module_id')
            ->join('classes', 'classes.id', '=', 'class_posts.class_id')
            ->whereNull('classes.subject_id')
            ->select('class_posts.class_id', 'modules.subject_id')
            ->distinct()
            ->get()
            ->groupBy('class_id');

        foreach ($subjectsByClass as $classId => $rows) {
            if ($rows->count() === 1) {
                DB::table('classes')->where('id', $classId)->update(['subject_id' => $rows->first()->subject_id]);
            }
        }
    }

    public function down(): void
    {
        // The links can't be told apart from ones made later; leave them.
    }
};
