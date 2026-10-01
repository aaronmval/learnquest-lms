<?php

namespace App\Http\Controllers;

use App\Models\ClassPost;
use App\Models\ClassRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudentClassPostController extends Controller
{
    /**
     * List a class's posts, newest first — for a student enrolled in it.
     */
    public function index(Request $request, ClassRoom $class): JsonResponse
    {
        $this->authorizeEnrolled($request, $class);

        return response()->json(
            $class->posts()->latest()->get()
        );
    }

    /**
     * Stream a post's attachment back — inline by default (so the PDF modal
     * viewer can render it), or as a forced download with ?download=1.
     */
    public function attachment(Request $request, ClassRoom $class, ClassPost $post)
    {
        $this->authorizeEnrolled($request, $class);
        $this->authorizePostBelongsToClass($class, $post);

        abort_unless($post->attachment_path, 404);
        abort_unless(Storage::disk()->exists($post->attachment_path), 404);

        $name = $post->attachment_name ?: basename($post->attachment_path);

        if ($request->boolean('download')) {
            return Storage::disk()->download($post->attachment_path, $name);
        }

        return Storage::disk()->response($post->attachment_path, $name);
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
