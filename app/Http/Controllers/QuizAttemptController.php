<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuizAttemptRequest;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Services\Notifications\AlertService;
use App\Services\Quiz\QuizAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuizAttemptController extends Controller
{
    /**
     * Submit and grade a full quiz attempt in one request. Correctness,
     * explanations, and mastery deltas are computed here and only here —
     * this is the sole endpoint that ever returns answer-bearing data, and
     * only after the student has already submitted.
     */
    public function store(
        StoreQuizAttemptRequest $request,
        ClassRoom $class,
        ClassPost $post,
        QuizAttemptService $attempts,
        AlertService $alerts,
    ): JsonResponse
    {
        $this->authorizeEnrolled($request, $class);
        $this->authorizePostBelongsToClass($class, $post);

        abort_unless($post->quiz, 404);

        $result = $attempts->submit($post->quiz, $request->user(), $request->validated()['answers']);
        $alerts->quizGraded($request->user(), $post, $result['mastery_deltas']);

        return response()->json($result, 201);
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
