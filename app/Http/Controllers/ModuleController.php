<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreModuleRequest;
use App\Models\Module;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

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
        $this->syncTargetSections($module, $subject, $request->input('section_ids'));
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
        $this->syncTargetSections($module, $subject, $request->input('section_ids'));
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
     * Sync which sections this module targets, restricted to sections that
     * actually belong to the subject. An empty/omitted list means "all
     * sections" (no rows in the pivot table).
     */
    private function syncTargetSections(Module $module, Subject $subject, ?array $sectionIds): void
    {
        $validIds = $subject->sections()->whereIn('id', $sectionIds ?? [])->pluck('id');
        $module->targetSections()->sync($validIds);
    }
}
