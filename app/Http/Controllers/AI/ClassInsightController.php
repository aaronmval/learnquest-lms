<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfessorAnalyticsRequest;
use App\Services\AI\ClassInsightService;
use App\Services\Learning\ProfessorAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ClassInsightController extends Controller
{
    /**
     * AI notes for the professor dashboard's strengths/weaknesses card:
     * class mastery from BKT, phrased as teaching advice by Llama.
     */
    public function show(ProfessorAnalyticsRequest $request, ProfessorAnalyticsService $analytics, ClassInsightService $insights): JsonResponse
    {
        $requestId = (string) Str::uuid();

        $dashboard = $analytics->dashboard(
            $request->user(),
            $request->classId(),
            $request->quarterName(),
            $request->startDate(),
            $request->endDate(),
            $request->subjectId(),
        );

        try {
            $result = $insights->insightsFor($request->user(), $dashboard, $request->boolean('refresh'));
        } catch (Throwable $e) {
            Log::channel('ai')->error('[controller] Class insight generation failed.', [
                'request_id' => $requestId,
                'user_id' => $request->user()->id,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "We couldn't generate the class analysis right now. Please try again shortly.",
                'request_id' => $requestId,
            ], 500);
        }

        return response()->json($result + ['request_id' => $requestId]);
    }
}
