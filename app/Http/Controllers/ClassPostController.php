<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClassPostRequest;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class ClassPostController extends Controller
{
    /**
     * List a class's posts, newest first.
     */
    public function index(Request $request, ClassRoom $class): JsonResponse
    {
        $this->authorizeOwner($request, $class);

        return response()->json(
            $class->posts()->latest()->get()
        );
    }

    /**
     * Create a new post (announcement or lesson) for the class.
     */
    public function store(StoreClassPostRequest $request, ClassRoom $class): JsonResponse
    {
        $this->authorizeOwner($request, $class);

        $data = $request->validated();
        $data['author_id'] = $request->user()->id;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $data['attachment_path'] = $file->store("class-posts/{$class->id}", 'local');
            $data['attachment_name'] = $file->getClientOriginalName();
        }

        $post = $class->posts()->create($data);

        return response()->json($post, 201);
    }

    /**
     * Update an existing post. Sent as POST with _method=PUT (multipart
     * form data, so a real PUT request isn't practical from the browser).
     */
    public function update(StoreClassPostRequest $request, ClassRoom $class, ClassPost $post): JsonResponse
    {
        $this->authorizeOwner($request, $class);
        $this->authorizePostBelongsToClass($class, $post);

        $data = $request->validated();
        $data['edited'] = true;

        if ($request->hasFile('attachment')) {
            if ($post->attachment_path) {
                Storage::disk('local')->delete($post->attachment_path);
            }

            $file = $request->file('attachment');
            $data['attachment_path'] = $file->store("class-posts/{$class->id}", 'local');
            $data['attachment_name'] = $file->getClientOriginalName();
        }

        $post->update($data);

        return response()->json($post);
    }

    /**
     * Delete a post and its stored attachment, if any.
     */
    public function destroy(Request $request, ClassRoom $class, ClassPost $post): Response
    {
        $this->authorizeOwner($request, $class);
        $this->authorizePostBelongsToClass($class, $post);

        if ($post->attachment_path) {
            Storage::disk('local')->delete($post->attachment_path);
        }

        $post->delete();

        return response()->noContent();
    }

    /**
     * Stream a post's attachment back — inline by default (so the PDF modal
     * viewer can render it), or as a forced download with ?download=1.
     */
    public function attachment(Request $request, ClassRoom $class, ClassPost $post)
    {
        $this->authorizeOwner($request, $class);
        $this->authorizePostBelongsToClass($class, $post);

        abort_unless($post->attachment_path, 404);
        abort_unless(Storage::disk('local')->exists($post->attachment_path), 404);

        $path = Storage::disk('local')->path($post->attachment_path);
        $name = $post->attachment_name ?: basename($post->attachment_path);

        if ($request->boolean('download')) {
            return Storage::disk('local')->download($post->attachment_path, $name);
        }

        return response()->file($path, [
            'Content-Disposition' => 'inline; filename="'.addslashes($name).'"',
        ]);
    }

    private function authorizeOwner(Request $request, ClassRoom $class): void
    {
        abort_unless($class->professor_id === $request->user()->id, 404);
    }

    private function authorizePostBelongsToClass(ClassRoom $class, ClassPost $post): void
    {
        abort_unless($post->class_id === $class->id, 404);
    }
}
