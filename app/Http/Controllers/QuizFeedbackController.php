<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuizFeedbackRequest;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Notifications\AlertService;
use App\Services\Quiz\QuizFeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuizFeedbackController extends Controller
{
    /**
     * Record a student's feedback on their own quiz attempt — one
     * submission per attempt, create-only (never edited or deleted).
     */
    public function store(
        StoreQuizFeedbackRequest $request,
        ClassRoom $class,
        ClassPost $post,
        QuizAttempt $attempt,
        QuizFeedbackService $feedback,
        AlertService $alerts,
    ): JsonResponse {
        $this->authorizeEnrolled($request, $class);
        $this->authorizePostBelongsToClass($class, $post);
        $this->authorizeAttempt($attempt, $post, $request->user());

        if ($attempt->feedback()->exists()) {
            return response()->json([
                'message' => 'Feedback has already been submitted for this attempt.',
            ], 422);
        }

        $record = $feedback->submit($attempt, $request->user(), $request->validated());
        $alerts->quizFeedbackReceived($record, $post);

        return response()->json(['id' => $record->id], 201);
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

    private function authorizeAttempt(QuizAttempt $attempt, ClassPost $post, User $user): void
    {
        abort_unless($attempt->student_id === $user->id, 404);
        abort_unless($attempt->quiz->class_post_id === $post->id, 404);
    }
}
