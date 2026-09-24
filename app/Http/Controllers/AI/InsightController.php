<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Models\ClassRoom;
use App\Services\AI\InsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class InsightController extends Controller
{
    /**
     * Personalized insights for the "AI Insights for You" card: mastery
     * level and weaknesses from BKT, phrased as advice by Llama.
     */
    public function show(Request $request, ClassRoom $class, InsightService $insights): JsonResponse
    {
        $requestId = (string) Str::uuid();

        $this->authorizeEnrolled($request, $class);

        try {
            $result = $insights->insightsForClass($request->user(), $class, $request->boolean('refresh'));
        } catch (Throwable $e) {
            Log::channel('ai')->error('[controller] Insight generation failed.', [
                'request_id' => $requestId,
                'class_id' => $class->id,
                'user_id' => $request->user()->id,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "We couldn't load your insights right now. Please try again shortly.",
                'request_id' => $requestId,
            ], 500);
        }

        return response()->json($result + ['request_id' => $requestId]);
    }

    private function authorizeEnrolled(Request $request, ClassRoom $class): void
    {
        $isEnrolled = $class->students()->where('users.id', $request->user()->id)->exists();

        abort_unless($isEnrolled, 404);
    }
}
