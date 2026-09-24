<?php

namespace App\Http\Controllers;

use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Services\Bkt\BayesianKnowledgeTracingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentMasteryController extends Controller
{
    /**
     * Average BKT mastery across every competency defined for the class's
     * subject — the "all lessons combined" mastery shown on the class banner.
     */
    public function forClass(Request $request, ClassRoom $class, BayesianKnowledgeTracingService $bkt): JsonResponse
    {
        $this->authorizeEnrolled($request, $class);

        $subject = $class->parentSubject;
        $competencies = $subject ? $subject->competencies()->get() : collect();

        return response()->json([
            'average_mastery' => $bkt->averageMasteryForCompetencies($request->user(), $competencies),
            'competency_count' => $competencies->count(),
        ]);
    }

    /**
     * Average BKT mastery across just this lesson's tested competencies
     * (i.e. the competencies its AI-generated quiz's questions are tagged
     * with) — shown on the lesson banner. Null until a quiz has been
     * generated for this lesson.
     */
    public function forPost(Request $request, ClassRoom $class, ClassPost $post, BayesianKnowledgeTracingService $bkt): JsonResponse
    {
        $this->authorizeEnrolled($request, $class);
        $this->authorizePostBelongsToClass($class, $post);

        $quiz = $post->quiz;
        $competencies = $quiz
            ? $quiz->questions()->with('competency')->get()->pluck('competency')->unique('id')->values()
            : collect();

        return response()->json([
            'lesson_mastery' => $bkt->averageMasteryForCompetencies($request->user(), $competencies),
            'competency_count' => $competencies->count(),
        ]);
    }

    private function authorizeEnrolled(Request $request, ClassRoom $class): void
    {
        $isEnrolled = $class->students()->where('users.id', $request->user()->id)->exists();

        abort_unless($isEnrolled, 404);
    }

    private function authorizePostBelongsToClass(ClassRoom $class, ClassPost $post): void
    {
        abort_unless($post->class_id === $class->id, 404);
    }
}
