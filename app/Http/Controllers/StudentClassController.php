<?php

namespace App\Http\Controllers;

use App\Http\Requests\JoinClassRequest;
use App\Models\ClassRoom;
use App\Services\Notifications\AlertService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentClassController extends Controller
{
    /**
     * List the authenticated student's enrolled classes.
     */
    public function index(Request $request): JsonResponse
    {
        $classes = $request->user()->enrolledClasses()
            ->with('professor:id,name,avatar_path')
            ->orderByDesc('class_enrollments.created_at')
            ->get(['classes.id', 'classes.professor_id', 'classes.name', 'classes.section', 'classes.subject', 'classes.room']);

        return response()->json($classes);
    }

    /**
     * Look up a class by its invite code, for the Join Class modal's live
     * preview. Does not enroll — read-only.
     */
    public function lookup(string $code): JsonResponse
    {
        $class = ClassRoom::where('code', strtoupper(trim($code)))
            ->with('professor:id,name,avatar_path')
            ->first(['id', 'name', 'section', 'subject', 'code', 'professor_id']);

        abort_unless($class, 404);

        return response()->json($class);
    }

    /**
     * Enroll the authenticated student in a class via its invite code.
     */
    public function join(JoinClassRequest $request, AlertService $alerts): JsonResponse
    {
        $code = strtoupper(trim($request->validated('code')));

        $class = ClassRoom::where('code', $code)->with('professor:id,name,avatar_path')->first();

        abort_unless($class, 404, 'Class code not found.');

        $studentId = $request->user()->id;

        if ($class->students()->where('users.id', $studentId)->exists()) {
            return response()->json(['message' => 'You are already enrolled in this class.'], 422);
        }

        try {
            $class->students()->attach($studentId);
        } catch (QueryException) {
            // Race between two rapid clicks hitting the unique constraint —
            // surface the same friendly message instead of a 500.
            return response()->json(['message' => 'You are already enrolled in this class.'], 422);
        }

        $alerts->studentJoined($class, $request->user());

        return response()->json($class, 201);
    }

    /**
     * Show a class the student is enrolled in.
     */
    public function show(Request $request, ClassRoom $class): JsonResponse
    {
        $isEnrolled = $class->students()->where('users.id', $request->user()->id)->exists();

        abort_unless($isEnrolled, 404);

        $class->load('professor:id,name,avatar_path');

        return response()->json($class);
    }
}
