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
            ->with(['owner:id,name,email', 'collaborators:id,name,email'])
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
        $subject->load(['owner:id,name,email', 'collaborators:id,name,email']);

        return response()->json($subject, 201);
    }

    /**
     * Show a single subject, its sections, modules, and collaborators.
     */
    public function show(Request $request, Subject $subject): JsonResponse
    {
        abort_unless($subject->isManagedBy($request->user()), 404);

        $subject->load([
            'owner:id,name,email',
            'collaborators:id,name,email',
            'sections' => fn ($q) => $q->withCount('students'),
            'modules' => fn ($q) => $q->with(['uploader:id,name', 'targetSections:id'])->latest(),
        ]);

        return response()->json($subject);
    }
}
