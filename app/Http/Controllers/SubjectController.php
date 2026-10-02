<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubjectRequest;
use App\Models\Module;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
