<?php

namespace App\Http\Controllers\AI;

use App\Exceptions\AI\NoSourceContentException;
use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Services\AI\CompetencySuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CompetencySuggestionController extends Controller
{
    /**
     * Suggest competencies for a subject from its uploaded modules. Nothing
     * is saved — the professor picks which suggestions to add.
     */
    public function store(Request $request, Subject $subject, CompetencySuggestionService $suggestions): JsonResponse
    {
        abort_unless($subject->isManagedBy($request->user()), 404);

        $requestId = (string) Str::uuid();

        try {
            $result = $suggestions->suggestFor($subject);
        } catch (NoSourceContentException $e) {
            return response()->json(['message' => $e->getMessage(), 'request_id' => $requestId], 422);
        } catch (Throwable $e) {
            Log::channel('ai')->error('[controller] Competency suggestion failed.', [
                'request_id' => $requestId,
                'user_id' => $request->user()->id,
                'subject_id' => $subject->id,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "We couldn't generate suggestions right now. Please try again shortly.",
                'request_id' => $requestId,
            ], 503);
        }

        return response()->json($result + ['request_id' => $requestId]);
    }
}
