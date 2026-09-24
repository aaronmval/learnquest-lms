<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompetencyRequest;
use App\Models\Competency;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CompetencyController extends Controller
{
    /**
     * List a subject's competencies.
     */
    public function index(Request $request, Subject $subject): JsonResponse
    {
        $this->authorizeManager($request, $subject);

        return response()->json($subject->competencies()->orderBy('name')->get());
    }

    /**
     * Define a new competency under the subject.
     */
    public function store(StoreCompetencyRequest $request, Subject $subject): JsonResponse
    {
        $this->authorizeManager($request, $subject);

        $competency = $subject->competencies()->create($request->validated());

        return response()->json($competency, 201);
    }

    /**
     * Rename/update a competency.
     */
    public function update(StoreCompetencyRequest $request, Subject $subject, Competency $competency): JsonResponse
    {
        $this->authorizeManager($request, $subject);
        $this->authorizeCompetencyBelongsToSubject($subject, $competency);

        $competency->update($request->validated());

        return response()->json($competency);
    }

    /**
     * Delete a competency — refused while it's still referenced by quiz
     * questions or student mastery records, so historical quiz/BKT data is
     * never silently orphaned.
     */
    public function destroy(Request $request, Subject $subject, Competency $competency): Response|JsonResponse
    {
        $this->authorizeManager($request, $subject);
        $this->authorizeCompetencyBelongsToSubject($subject, $competency);

        if ($competency->quizQuestions()->exists() || $competency->masteryRecords()->exists()) {
            return response()->json([
                'message' => 'This competency is in use by existing quiz questions or student mastery records and cannot be deleted.',
            ], 422);
        }

        $competency->delete();

        return response()->noContent();
    }

    private function authorizeManager(Request $request, Subject $subject): void
    {
        abort_unless($subject->isManagedBy($request->user()), 404);
    }

    private function authorizeCompetencyBelongsToSubject(Subject $subject, Competency $competency): void
    {
        abort_unless($competency->subject_id === $subject->id, 404);
    }
}
