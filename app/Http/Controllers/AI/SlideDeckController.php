<?php

namespace App\Http\Controllers\AI;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Exceptions\AI\LlamaApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateSlideDeckRequest;
use App\Services\AI\SlideDeckGenerationService;
use App\Services\Documents\PdfTextExtractorService;
use App\Services\Documents\PresentationBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * QuestAI Coach "Generate a Presentation": PDF → extracted text → Llama
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
    ): JsonResponse {
        $requestId = (string) Str::uuid();
        $professor = $request->user();
        $file = $request->file('file');
        $slideCount = (int) $request->validated('slide_count');
        $theme = $request->validated('theme');
        $sourceName = $file->getClientOriginalName();

        try {
            $text = $extractor->extractText($file->getRealPath());
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage().' Try a PDF with selectable text.',
                'request_id' => $requestId,
            ], 422);
        }

        try {
            $deck = $generator->generate($sourceName, $text, $slideCount);
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
            'download_url' => route('professor.questai.decks.download', $fileId),
            'request_id' => $requestId,
        ], 201);
    }

    public function download(Request $request, string $deck): BinaryFileResponse
    {
        $entry = Str::isUuid($deck) ? Cache::get($this->cacheKey($deck)) : null;

        abort_unless(
            $entry
                && $entry['professor_id'] === $request->user()->id
                && Storage::disk('local')->exists($entry['path']),
            404,
        );

        return response()->download(
            Storage::disk('local')->path($entry['path']),
            $entry['filename'],
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        );
    }

    private function cacheKey(string $fileId): string
    {
        return "slide-deck:{$fileId}";
    }
}
