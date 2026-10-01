<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubjectRequest;
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
            ->withCount(['sections', 'modules'])
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
     * Show a single subject, its sections, modules, and collaborators.
     */
    public function show(Request $request, Subject $subject): JsonResponse
    {
        abort_unless($subject->isManagedBy($request->user()), 404);

        $subject->load([
            'owner:id,name,email,avatar_path',
            'collaborators:id,name,email,avatar_path',
            'sections' => fn ($q) => $q->withCount('students'),
            'modules' => fn ($q) => $q->with(['uploader:id,name,avatar_path', 'targetSections:id,name,section'])->latest(),
        ]);

        // The classes this professor may tick when uploading a module.
        $targetable = $subject->targetableSectionsFor($request->user())
            ->orderBy('name')
            ->orderBy('section')
            ->get(['id', 'name', 'section']);

        return response()->json($subject->toArray() + ['targetable_sections' => $targetable]);
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
