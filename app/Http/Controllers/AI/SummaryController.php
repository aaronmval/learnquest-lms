<?php

namespace App\Http\Controllers\AI;

use App\Exceptions\AI\LlamaApiException;
use App\Http\Controllers\Controller;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Services\AI\SummarizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SummaryController extends Controller
{
    /**
     * Return the AI-generated summary for a lesson post's attached PDF,
     * generating and caching it on first request.
     */
    public function show(Request $request, ClassRoom $class, ClassPost $post, SummarizationService $summarizer): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $log = Log::channel('ai');
        $startedAt = microtime(true);

        $this->authorizeEnrolled($request, $class);
        $this->authorizePostBelongsToClass($class, $post);

        abort_unless($post->type === 'lesson', 404);
        abort_unless($post->attachment_path, 404);

        $log->debug('[controller] Summary requested.', [
            'request_id' => $requestId,
            'class_id' => $class->id,
            'class_post_id' => $post->id,
            'user_id' => $request->user()->id,
        ]);

        $existing = $post->summary;

        if ($existing) {
            $log->info('[controller] Serving cached summary.', [
                'request_id' => $requestId,
                'class_post_id' => $post->id,
                'lesson_summary_id' => $existing->id,
            ]);

            return response()->json([
                'overview' => $existing->overview,
                'key_points' => $existing->key_points,
                'generated_at' => $existing->generated_at,
                'model' => $existing->model,
                'cached' => true,
                'request_id' => $requestId,
            ]);
        }

        try {
            $summary = $summarizer->summarizeLessonPost($post);
        } catch (LlamaApiException $e) {
            $log->error('[controller] Summary generation failed (AI service).', [
                'request_id' => $requestId,
                'class_post_id' => $post->id,
                'duration_ms' => $this->durationMs($startedAt),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'The AI summary service is temporarily unavailable. Please try again shortly.',
                'request_id' => $requestId,
            ], 502);
        } catch (Throwable $e) {
            $log->error('[controller] Summary generation failed.', [
                'request_id' => $requestId,
                'class_post_id' => $post->id,
                'duration_ms' => $this->durationMs($startedAt),
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "We couldn't generate a summary for this lesson.",
                'request_id' => $requestId,
            ], 500);
        }

        $log->info('[controller] Summary generated successfully.', [
            'request_id' => $requestId,
            'class_post_id' => $post->id,
            'duration_ms' => $this->durationMs($startedAt),
        ]);

        return response()->json([
            'overview' => $summary->overview,
            'key_points' => $summary->key_points,
            'generated_at' => $summary->generated_at,
            'model' => $summary->model,
            'cached' => false,
            'request_id' => $requestId,
        ], 201);
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
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
