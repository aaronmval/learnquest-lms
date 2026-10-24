<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubjectRequest;
use App\Models\Module;
use App\Models\QuizAnswer;
use App\Models\QuizQuestion;
use App\Models\StudentMastery;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class SubjectController extends Controller
{
    /**
     * List the subjects the authenticated professor owns or collaborates on.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $subjects = Subject::where('owner_id', $userId)
            ->orWhereHas('collaborators', fn ($q) => $q->where('user_id', $userId))
            ->with(['owner:id,name,email,avatar_path', 'collaborators:id,name,email,avatar_path'])
            // Each professor's own sections only, even on a shared subject.
            ->withCount(['sections' => fn ($q) => $q->where('professor_id', $userId), 'modules'])
            ->withSum('modules', 'file_size')
            ->withMax('modules', 'created_at')
            ->latest()
            ->get();

        // Deleting a subject is for its owner only.
        $subjects = $subjects->map(fn (Subject $subject) => $subject->toArray() + ['can_delete' => $subject->owner_id === $userId]);

        return response()->json($subjects);
    }

    /**
     * Create a new subject owned by the authenticated professor.
     */
    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $subject = $request->user()->ownedSubjects()->create($request->validated());
        $subject->load(['owner:id,name,email,avatar_path', 'collaborators:id,name,email,avatar_path']);

        return response()->json($subject, 201);
    }

    /**
     * Show a single subject, its modules and collaborators, and the
     * sections of it that this professor owns.
     */
    public function show(Request $request, Subject $subject): JsonResponse
    {
        abort_unless($subject->isManagedBy($request->user()), 404);

        $subject->load([
            'owner:id,name,email,avatar_path',
            'collaborators:id,name,email,avatar_path',
            'sections' => fn ($q) => $q->where('professor_id', $request->user()->id)->withCount('students'),
            'modules' => fn ($q) => $q->with(['uploader:id,name,avatar_path', 'targetSections:id,name,section'])->latest(),
        ]);

        // The classes this professor may post a module into.
        $targetable = $subject->targetableSectionsFor($request->user())
            ->orderBy('name')
            ->orderBy('section')
            ->get(['id', 'name', 'section']);

        // Editing/deleting a module is for the subject owner and its uploader.
        $modules = $subject->modules->map(
            fn (Module $module) => $module->toArray() + ['can_edit' => $module->canBeModifiedBy($request->user(), $subject)]
        );

        return response()->json(['modules' => $modules, 'targetable_sections' => $targetable] + $subject->toArray());
    }

    /**
     * Rename a subject.
     */
    public function update(StoreSubjectRequest $request, Subject $subject): JsonResponse
    {
        abort_unless($subject->isManagedBy($request->user()), 404);

        $subject->update($request->validated());
        $subject->load(['owner:id,name,email,avatar_path', 'collaborators:id,name,email,avatar_path']);

        return response()->json($subject);
    }

    /**
     * Delete a subject with its modules, competencies and collaborator list.
     * Only the owner may do this. Its sections stay (unlinked from the
     * subject), and a subject whose competencies already back quiz questions
     * or student mastery records is refused so BKT history isn't lost.
     */
    public function destroy(Request $request, Subject $subject): Response|JsonResponse
    {
        abort_unless($subject->isManagedBy($request->user()), 404);
        abort_unless($subject->owner_id === $request->user()->id, 403, 'Only the subject owner can delete it.');

        $competencyIds = $subject->competencies()->pluck('id');
        $inUse = QuizQuestion::whereIn('competency_id', $competencyIds)->exists()
            || QuizAnswer::whereIn('competency_id', $competencyIds)->exists()
            || StudentMastery::whereIn('competency_id', $competencyIds)->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'This subject has quiz questions or student mastery records and cannot be deleted.',
            ], 422);
        }

        $filePaths = $subject->modules()->pluck('file_path')->filter()->all();

        $subject->delete();
        Storage::disk()->delete($filePaths);

        return response()->noContent();
    }
}
