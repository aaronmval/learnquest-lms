<?php

namespace App\Http\Controllers\AI;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Exceptions\AI\LlamaApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateSlideDeckRequest;
use App\Models\Module;
use App\Services\AI\SlideDeckFocusService;
use App\Services\AI\SlideDeckGenerationService;
use App\Services\Documents\PdfTextExtractorService;
use App\Services\Documents\PresentationBuilderService;
use App\Services\Documents\StoredFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * QuestAI Coach "Generate a Presentation": PDF (uploaded, or an existing
 * module's) → extracted text → Llama
 * slide outline (validated) → .pptx the professor can download.
 */
class SlideDeckController extends Controller
{
    private const DOWNLOAD_TTL_SECONDS = 86400;

    public function store(
        GenerateSlideDeckRequest $request,
        PdfTextExtractorService $extractor,
        SlideDeckGenerationService $generator,
        PresentationBuilderService $builder,
        SlideDeckFocusService $focus,
    ): JsonResponse {
        $requestId = (string) Str::uuid();
        $professor = $request->user();
        $slideCount = (int) $request->validated('slide_count');
        $theme = $request->validated('theme');

        // Optional targeted focus from the chosen class's BKT mastery; a
        // class the professor doesn't manage is a 404.
        $focusPlan = $request->filled('focus_class_id')
            ? $focus->plan($professor, (int) $request->validated('focus_class_id'))
            : null;

        $module = null;

        if ($file = $request->file('file')) {
            $sourceName = $file->getClientOriginalName();
        } else {
            // An existing module from the Modules page, limited to subjects
            // this professor owns or collaborates on.
            $module = Module::with('subject')->findOrFail($request->validated('module_id'));
            abort_unless($module->subject?->isManagedBy($professor), 404);

            if (! $module->file_path || ! Storage::disk()->exists($module->file_path)) {
                return response()->json([
                    'message' => "This module's PDF could not be found. Re-upload it on the Modules page and try again.",
                    'request_id' => $requestId,
                ], 422);
            }

            $sourceName = $module->file_name ?: "{$module->title}.pdf";
        }

        try {
            $text = $module
                ? StoredFile::withLocalPath($module->file_path, fn (string $path) => $extractor->extractText($path))
                : $extractor->extractText($file->getRealPath());
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage().' Try a PDF with selectable text.',
                'request_id' => $requestId,
            ], 422);
        }

        try {
            $deck = $generator->generate(
                $sourceName,
                $text,
                $slideCount,
                $focusPlan && $focus->isNeeded($focusPlan) ? $focusPlan : null,
            );
            $fileId = (string) Str::uuid();
            $path = $builder->build($deck, $fileId, $theme, $professor->name);
        } catch (LlamaApiException|InvalidAiResponseException $e) {
            Log::channel('ai')->error('[controller] Slide deck generation failed (AI service).', [
                'request_id' => $requestId,
                'user_id' => $professor->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "QuestAI couldn't draft the slides right now. Please try again in a moment.",
                'request_id' => $requestId,
            ], 502);
        } catch (Throwable $e) {
            Log::channel('ai')->error('[controller] Slide deck generation failed.', [
                'request_id' => $requestId,
                'user_id' => $professor->id,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Something went wrong while building the presentation. Please try again.',
                'request_id' => $requestId,
            ], 500);
        }

        $baseName = Str::slug(pathinfo($sourceName, PATHINFO_FILENAME)) ?: 'presentation';

        Cache::put($this->cacheKey($fileId), [
            'professor_id' => $professor->id,
            'path' => $path,
            'filename' => "{$baseName}-slides.pptx",
        ], self::DOWNLOAD_TTL_SECONDS);

        return response()->json([
            'title' => $deck['title'],
            'slide_count' => count($deck['slides']),
            'theme' => $theme,
            // null when no focus was requested; otherwise whether it was applied and why.
            'focus' => $focusPlan ? $focus->report($focusPlan, $deck) : null,
            'download_url' => route('professor.questai.decks.download', $fileId),
            'request_id' => $requestId,
        ], 201);
    }

    public function download(Request $request, string $deck): StreamedResponse
    {
        $entry = Str::isUuid($deck) ? Cache::get($this->cacheKey($deck)) : null;

        abort_unless(
            $entry
                && $entry['professor_id'] === $request->user()->id
                && Storage::disk()->exists($entry['path']),
            404,
        );

        return Storage::disk()->download(
            $entry['path'],
            $entry['filename'],
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        );
    }

    private function cacheKey(string $fileId): string
    {
        return "slide-deck:{$fileId}";
    }
}
