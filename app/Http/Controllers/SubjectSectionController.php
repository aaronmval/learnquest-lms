<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSectionRequest;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;

class SubjectSectionController extends Controller
{
    /**
     * Add a new section (class) under a subject.
     */
    public function store(StoreSectionRequest $request, Subject $subject): JsonResponse
    {
        abort_unless($subject->isManagedBy($request->user()), 404);

        $section = $subject->sections()->create([
            ...$request->validated(),
            'professor_id' => $request->user()->id,
        ]);

        return response()->json($section, 201);
    }
}
