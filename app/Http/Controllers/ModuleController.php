<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreModuleRequest;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Module;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ModuleController extends Controller
{
    /**
     * List a subject's modules, newest first.
     */
    public function index(Request $request, Subject $subject): JsonResponse
    {
        $this->authorizeManager($request, $subject);

        $modules = $subject->modules()
            ->with(['uploader:id,name', 'targetSections:id'])
            ->latest()
            ->get();

        return response()->json($modules);
    }

    /**
     * Upload a new PDF module under the subject.
     */
    public function store(StoreModuleRequest $request, Subject $subject): JsonResponse
    {
        $this->authorizeManager($request, $subject);

        $data = $request->safe()->except(['attachment', 'section_ids']);
        $data['uploaded_by'] = $request->user()->id;

        if (! $request->hasFile('attachment')) {
            return response()->json(['message' => 'A PDF file is required.'], 422);
        }

        $file = $request->file('attachment');
        $data['file_path'] = $file->store("subject-modules/{$subject->id}", 'local');
        $data['file_name'] = $file->getClientOriginalName();
        $data['file_size'] = $file->getSize();

        $module = $subject->modules()->create($data);
        $targetedClassIds = $this->syncTargetSections($request, $module, $subject, $request->input('section_ids'));
        $this->createLessonPostsForNewlyTargetedClasses($request, $module, $targetedClassIds);
        $module->load(['uploader:id,name', 'targetSections:id']);

        return response()->json($module, 201);
    }

    /**
     * Update an existing module. Sent as POST with _method=PUT (multipart
     * form data, so a real PUT request isn't practical from the browser).
     */
    public function update(StoreModuleRequest $request, Subject $subject, Module $module): JsonResponse
    {
        $this->authorizeManager($request, $subject);
        $this->authorizeModuleBelongsToSubject($subject, $module);

        $data = $request->safe()->except(['attachment', 'section_ids']);

        if ($request->hasFile('attachment')) {
            Storage::disk('local')->delete($module->file_path);

            $file = $request->file('attachment');
            $data['file_path'] = $file->store("subject-modules/{$subject->id}", 'local');
            $data['file_name'] = $file->getClientOriginalName();
            $data['file_size'] = $file->getSize();
        }

        $module->update($data);
        $targetedClassIds = $this->syncTargetSections($request, $module, $subject, $request->input('section_ids'));
        $this->createLessonPostsForNewlyTargetedClasses($request, $module, $targetedClassIds);
        $module->load(['uploader:id,name', 'targetSections:id']);

        return response()->json($module);
    }

    /**
     * Delete a module and its stored file.
     */
    public function destroy(Request $request, Subject $subject, Module $module): Response
    {
        $this->authorizeManager($request, $subject);
        $this->authorizeModuleBelongsToSubject($subject, $module);

        Storage::disk('local')->delete($module->file_path);
        $module->delete();

        return response()->noContent();
    }

    /**
     * Stream a module's PDF back — inline by default (for the in-page
     * preview), or as a forced download with ?download=1.
     */
    public function attachment(Request $request, Subject $subject, Module $module)
    {
        $this->authorizeManager($request, $subject);
        $this->authorizeModuleBelongsToSubject($subject, $module);

        abort_unless(Storage::disk('local')->exists($module->file_path), 404);

        $path = Storage::disk('local')->path($module->file_path);
        $name = $module->file_name ?: basename($module->file_path);

        if ($request->boolean('download')) {
            return Storage::disk('local')->download($module->file_path, $name);
        }

        return response()->file($path, [
            'Content-Disposition' => 'inline; filename="'.addslashes($name).'"',
        ]);
    }

    private function authorizeManager(Request $request, Subject $subject): void
    {
        abort_unless($subject->isManagedBy($request->user()), 404);
    }

    private function authorizeModuleBelongsToSubject(Subject $subject, Module $module): void
    {
        abort_unless($module->subject_id === $subject->id, 404);
    }

    /**
     * Sync which classes this module targets. Allowed targets are this
     * subject's own sections, or any class the uploading professor owns
     * (so a module can also be posted to their standalone classes). An
     * empty/omitted list means "all sections" (no rows in the pivot table).
     * Returns the resolved, valid target class ids.
     */
    private function syncTargetSections(Request $request, Module $module, Subject $subject, ?array $sectionIds): Collection
    {
        $validIds = ClassRoom::whereIn('id', $sectionIds ?? [])
            ->where(function ($query) use ($subject, $request) {
                $query->where('subject_id', $subject->id)
                    ->orWhere('professor_id', $request->user()->id);
            })
            ->pluck('id');

        $module->targetSections()->sync($validIds);

        return $validIds;
    }

    /**
     * Post the module into every newly-targeted class's feed as a "lesson"
     * post carrying a copy of its PDF. Classes that already have a post for
     * this module (from an earlier save) are skipped, so re-saving never
     * duplicates and unchecking a class never removes its existing post —
     * this only ever adds rows.
     */
    private function createLessonPostsForNewlyTargetedClasses(Request $request, Module $module, Collection $targetedClassIds): void
    {
        if ($targetedClassIds->isEmpty()) {
            return;
        }

        $alreadyPosted = ClassPost::where('module_id', $module->id)->pluck('class_id');
        $newIds = $targetedClassIds->diff($alreadyPosted);

        if ($newIds->isEmpty()) {
            return;
        }

        $quarter = $request->input('quarter') ?: '1st Quarter';

        foreach ($newIds as $classId) {
            $attachmentPath = null;
            $attachmentName = null;

            if ($module->file_path && Storage::disk('local')->exists($module->file_path)) {
                $extension = pathinfo($module->file_path, PATHINFO_EXTENSION) ?: 'pdf';
                $attachmentPath = "class-posts/{$classId}/".Str::random(40).".{$extension}";
                Storage::disk('local')->copy($module->file_path, $attachmentPath);
                $attachmentName = $module->file_name;
            }

            ClassPost::create([
                'class_id' => $classId,
                'author_id' => $request->user()->id,
                'module_id' => $module->id,
                'type' => 'lesson',
                'quarter' => $quarter,
                'title' => $module->title,
                'body' => $module->description,
                'attachment_path' => $attachmentPath,
                'attachment_name' => $attachmentName,
            ]);
        }
    }
}
