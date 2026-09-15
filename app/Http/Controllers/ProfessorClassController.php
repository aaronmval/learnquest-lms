<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClassRequest;
use App\Models\ClassRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfessorClassController extends Controller
{
    /**
     * List the authenticated professor's own classes.
     */
    public function index(Request $request): JsonResponse
    {
        $classes = $request->user()->classes()->latest()->get([
            'id', 'name', 'section', 'subject', 'room', 'created_at',
        ]);

        return response()->json($classes);
    }

    /**
     * Create a new class owned by the authenticated professor.
     */
    public function store(StoreClassRequest $request): JsonResponse
    {
        $class = $request->user()->classes()->create($request->validated());

        return response()->json($class, 201);
    }

    /**
     * Show a single class, scoped to its owning professor.
     */
    public function show(Request $request, ClassRoom $class): JsonResponse
    {
        abort_unless($class->professor_id === $request->user()->id, 404);

        $class->load('professor:id,name');

        return response()->json($class);
    }
}
