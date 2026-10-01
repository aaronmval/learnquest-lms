<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClassRequest;
use App\Models\ClassRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class ProfessorClassController extends Controller
{
    private const LIST_COLUMNS = [
        'id', 'name', 'section', 'subject', 'room', 'code', 'archived_at', 'created_at',
    ];

    /**
     * List the authenticated professor's own, non-archived classes.
     */
    public function index(Request $request): JsonResponse
    {
        $classes = $request->user()->classes()
            ->whereNull('archived_at')
            ->withCount('students')
            ->latest()
            ->get(self::LIST_COLUMNS);

        return response()->json($classes);
    }

    /**
     * List the authenticated professor's archived classes, most recently
     * archived first.
     */
    public function archived(Request $request): JsonResponse
    {
        $classes = $request->user()->classes()
            ->whereNotNull('archived_at')
            ->withCount('students')
            ->orderByDesc('archived_at')
            ->get(self::LIST_COLUMNS);

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
        $class->loadCount('students');

        return response()->json($class);
    }

    /**
     * Rename a class (its title and/or section). The invite code and
     * enrolled students are unaffected.
     */
    public function update(StoreClassRequest $request, ClassRoom $class): JsonResponse
    {
        abort_unless($class->professor_id === $request->user()->id, 404);

        $class->update($request->validated());

        return response()->json($class);
    }

    /**
     * Replace a class's invite code with a freshly generated one.
     */
    public function regenerateCode(Request $request, ClassRoom $class): JsonResponse
    {
        abort_unless($class->professor_id === $request->user()->id, 404);

        $class->regenerateCode();

        return response()->json($class);
    }

    /**
     * Archive a class — a reversible, professor-side-only filter that hides
     * it from the active class grid. Does not affect enrolled students.
     */
    public function archive(Request $request, ClassRoom $class): JsonResponse
    {
        abort_unless($class->professor_id === $request->user()->id, 404);

        $class->update(['archived_at' => now()]);

        return response()->json($class);
    }

    /**
     * Restore a previously archived class back to the active class grid.
     */
    public function restore(Request $request, ClassRoom $class): JsonResponse
    {
        abort_unless($class->professor_id === $request->user()->id, 404);

        $class->update(['archived_at' => null]);

        return response()->json($class);
    }

    /**
     * Permanently delete an archived class and its data. Only allowed once
     * a class has been archived first — this is the "empty the trash" step,
     * not a shortcut for deleting an active class.
     */
    public function destroy(Request $request, ClassRoom $class): Response
    {
        abort_unless($class->professor_id === $request->user()->id, 404);
        abort_unless($class->archived_at !== null, 422, 'Archive this class before deleting it.');

        foreach ($class->posts as $post) {
            if ($post->attachment_path) {
                Storage::disk()->delete($post->attachment_path);
            }
        }

        $class->delete();

        return response()->noContent();
    }
}
