<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuizAttemptRequest;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\QuizAttempt;
use App\Services\Notifications\AlertService;
use App\Services\Quiz\QuizAttemptService;
use App\Services\Quiz\QuizSettingsService;
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
        QuizSettingsService $settingsService,
        AlertService $alerts,
    ): JsonResponse
    {
        $this->authorizeEnrolled($request, $class);
        $this->authorizePostBelongsToClass($class, $post);

        $quiz = $post->quiz;
        abort_unless($quiz, 404);

        $student = $request->user();
        $settings = $settingsService->for($post);

        // The in-progress attempt opened when a timed quiz was started.
        $openAttempt = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('student_id', $student->id)
            ->where('status', 'in_progress')
            ->latest('id')
            ->first();

        if ($settings['max_attempts'] !== null && $settingsService->attemptsUsed($post, $student) >= $settings['max_attempts']) {
            return response()->json([
                'message' => "You've used all {$settings['max_attempts']} attempt(s) allowed for this quiz.",
                'max_attempts' => $settings['max_attempts'],
            ], 403);
        }

        $result = $attempts->submit($quiz, $student, $request->validated()['answers'], $openAttempt);
        $alerts->quizGraded($student, $post, $result['mastery_deltas']);

        // Teacher chose not to reveal answers: keep right/wrong, hide the key.
        if (! $settings['show_answers']) {
            $result['review'] = array_map(
                fn ($entry) => array_merge($entry, ['correct_index' => null, 'explanation' => null]),
                $result['review'],
            );
        }

        return response()->json($result + ['show_answers' => $settings['show_answers']], 201);
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
