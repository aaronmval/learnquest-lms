<?php

namespace App\Http\Controllers\AI;

use App\Exceptions\AI\LlamaApiException;
use App\Http\Controllers\Controller;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\AI\QuizGenerationService;
use App\Services\Learning\AdaptiveQuizService;
use App\Services\Quiz\QuizFeedbackService;
use App\Services\Quiz\QuizSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

class QuizGenerationController extends Controller
{
    /**
     * Return the AI-generated quiz for a lesson post's attached material,
     * generating and caching it on first request. The response never
     * includes correct_answer, explanation, competency_id, or difficulty —
     * only what's needed to render and answer the questions.
     */
    public function show(Request $request, ClassRoom $class, ClassPost $post, QuizGenerationService $generator, QuizSettingsService $settingsService, AdaptiveQuizService $adaptiveQuiz): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $log = Log::channel('ai');
        $startedAt = microtime(true);
        $student = $request->user();

        $this->authorizeEnrolled($request, $class);
        $this->authorizePostBelongsToClass($class, $post);

        abort_unless($post->type === 'lesson', 404);
        abort_unless($post->attachment_path, 404);

        $log->debug('[controller] Quiz requested.', [
            'request_id' => $requestId,
            'class_id' => $class->id,
            'class_post_id' => $post->id,
            'user_id' => $student->id,
        ]);

        $settings = $settingsService->for($post);
        $attemptsUsed = $settingsService->attemptsUsed($post, $student);

        if ($settings['max_attempts'] !== null && $attemptsUsed >= $settings['max_attempts']) {
            return response()->json([
                'message' => "You've used all {$settings['max_attempts']} attempt(s) allowed for this quiz.",
                'attempts_used' => $attemptsUsed,
                'max_attempts' => $settings['max_attempts'],
            ], 403);
        }

        $quiz = $post->quiz;
        $cached = $quiz !== null;

        if ($cached) {
            $log->info('[controller] Serving cached quiz.', [
                'request_id' => $requestId,
                'class_post_id' => $post->id,
                'quiz_id' => $quiz->id,
            ]);
        } else {
            try {
                $quiz = $generator->generateForPost($post);
            } catch (Throwable $e) {
                return $this->generationErrorResponse($e, $post, $requestId, $startedAt, $log, "We couldn't generate a quiz for this lesson.");
            }

            $log->info('[controller] Quiz generated successfully.', [
                'request_id' => $requestId,
                'class_post_id' => $post->id,
                'duration_ms' => $this->durationMs($startedAt),
            ]);
        }

        $active = $quiz->activeQuestions()->with('competency')->get();
        $attempt = $this->openAttempt($quiz, $student, $settings, $active, $adaptiveQuiz);

        return response()->json(
            $this->present($quiz, $post, $settings, $attemptsUsed, $attempt, $active, cached: $cached, requestId: $requestId),
            $cached ? 200 : 201,
        );
    }

    /**
     * Opening a quiz starts (or resumes) an in-progress attempt that records
     * which questions this student is served — chosen for their BKT mastery
     * when the quiz is adaptive — and, for timed quizzes, the deadline. A
     * reload resumes the same questions and clock.
     */
    private function openAttempt(Quiz $quiz, User $student, array $settings, Collection $active, AdaptiveQuizService $adaptiveQuiz): QuizAttempt
    {
        $open = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('student_id', $student->id)
            ->where('status', 'in_progress')
            ->latest('id')
            ->first();

        if ($open) {
            return $open;
        }

        $mastery = $settings['adaptive'] ? $adaptiveQuiz->masteryFor($student, $active) : null;
        $mix = $adaptiveQuiz->mixFor($settings['difficulty_mix'], $mastery['level'] ?? null);
        $questions = $adaptiveQuiz->select($active, $settings['question_count'], $mix);

        Log::channel('ai')->info('[quiz] Served quiz questions.', [
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'adaptive' => $settings['adaptive'],
            'mastery' => $mastery ? round($mastery['mastery'], 3) : null,
            'mastery_level' => $mastery['level'] ?? null,
            'mix' => $mix,
            'served' => $questions->countBy('difficulty'),
        ]);

        return QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'started_at' => now(),
            'deadline_at' => $settings['time_limit_minutes'] !== null ? now()->addMinutes($settings['time_limit_minutes']) : null,
            'question_ids' => $questions->pluck('id')->all(),
            'mastery_level' => $mastery['level'] ?? null,
            'status' => 'in_progress',
        ]);
    }

    /**
     * Archive the lesson's current quiz and generate a new version informed
     * by accumulated student feedback. Gated on there being enough feedback
     * to actually act on — this is what makes regeneration "use the data"
     * rather than an unconditional button.
     */
    public function regenerate(Request $request, ClassRoom $class, ClassPost $post, QuizGenerationService $generator, QuizFeedbackService $feedbackService): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $log = Log::channel('ai');
        $startedAt = microtime(true);

        $this->authorizeOwner($request, $class);
        $this->authorizePostBelongsToClass($class, $post);

        abort_unless($post->type === 'lesson', 404);

        $current = $post->quiz;

        if (! $current) {
            return response()->json([
                'message' => 'No quiz has been generated for this lesson yet.',
            ], 422);
        }

        $feedbackCount = $feedbackService->summaryForQuiz($current)['count'];
        $threshold = (int) config('quiz.min_feedback_for_regeneration', 1);

        if ($feedbackCount < $threshold) {
            return response()->json([
                'message' => "At least {$threshold} piece(s) of student feedback are needed before regenerating this quiz.",
                'feedback_count' => $feedbackCount,
                'threshold' => $threshold,
            ], 422);
        }

        try {
            $quiz = $generator->regenerateForPost($post);
        } catch (Throwable $e) {
            return $this->generationErrorResponse($e, $post, $requestId, $startedAt, $log, "We couldn't regenerate the quiz for this lesson.");
        }

        $log->info('[controller] Quiz regenerated successfully.', [
            'request_id' => $requestId,
            'class_post_id' => $post->id,
            'quiz_id' => $quiz->id,
            'duration_ms' => $this->durationMs($startedAt),
        ]);

        return response()->json([
            'quiz_id' => $quiz->id,
            'question_count' => $quiz->questions->count(),
            'generated_at' => $quiz->generated_at,
            'model' => $quiz->model,
            'request_id' => $requestId,
        ], 201);
    }

    /**
     * Aggregated student feedback for a lesson's current quiz, for the
     * professor to review before deciding whether to regenerate. Always
     * 200 — a professor should be able to open this for any lesson post
     * without knowing in advance whether a quiz exists yet.
     */
    public function feedback(Request $request, ClassRoom $class, ClassPost $post, QuizFeedbackService $feedbackService): JsonResponse
    {
        $this->authorizeOwner($request, $class);
        $this->authorizePostBelongsToClass($class, $post);

        $quiz = $post->quiz;

        if (! $quiz) {
            return response()->json(['has_quiz' => false, 'summary' => null]);
        }

        return response()->json([
            'has_quiz' => true,
            'quiz_id' => $quiz->id,
            'summary' => $feedbackService->summaryForQuiz($quiz),
            'min_feedback_for_regeneration' => (int) config('quiz.min_feedback_for_regeneration', 1),
        ]);
    }

    /**
     * Shared 502 (AI service failure) / 500 (everything else) mapping used
     * by both show() and regenerate() — same shape, different friendly
     * message per action.
     */
    private function generationErrorResponse(Throwable $e, ClassPost $post, string $requestId, float $startedAt, LoggerInterface $log, string $friendlyMessage): JsonResponse
    {
        if ($e instanceof LlamaApiException) {
            $log->error('[controller] Quiz generation failed (AI service).', [
                'request_id' => $requestId,
                'class_post_id' => $post->id,
                'duration_ms' => $this->durationMs($startedAt),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'The AI quiz service is temporarily unavailable. Please try again shortly.',
                'request_id' => $requestId,
            ], 502);
        }

        $log->error('[controller] Quiz generation failed.', [
            'request_id' => $requestId,
            'class_post_id' => $post->id,
            'duration_ms' => $this->durationMs($startedAt),
            'exception' => get_class($e),
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'message' => $friendlyMessage,
            'request_id' => $requestId,
        ], 500);
    }

    /**
     * Shape the response payload. Structurally excludes every answer-bearing
     * field — this is the one place in the feature that must never leak
     * correct_answer/explanation/competency_id/difficulty before submission.
     * Only questions a teacher hasn't rejected are included.
     */
    private function present(Quiz $quiz, ClassPost $post, array $settings, int $attemptsUsed, QuizAttempt $attempt, Collection $active, bool $cached, string $requestId): array
    {
        // The attempt's served questions (attempts opened before adaptive
        // quizzes existed have none recorded — they get every active question).
        $questions = $attempt->question_ids !== null
            ? $active->whereIn('id', $attempt->question_ids)->values()
            : $active;

        // Random order per the teacher's setting. Safe because the quiz page,
        // grading and review all key answers by question id, not position.
        if ($settings['shuffle_questions']) {
            $questions = $questions->shuffle();
        }

        return [
            'quiz_id' => $quiz->id,
            'lesson_title' => $post->title,
            'subject' => $post->classRoom?->subject ?? $post->classRoom?->name ?? 'Science',
            'section' => $post->classRoom?->section,
            'questions' => $questions->map(function ($q) use ($settings) {
                $order = array_keys($q->choices);

                if ($settings['shuffle_choices']) {
                    shuffle($order);
                }

                // choice_indexes[i] = original index of the i-th displayed
                // choice; the page submits original indexes, so grading
                // never depends on the display order.
                return [
                    'id' => $q->id,
                    'text' => $q->question_text,
                    'choices' => array_map(fn ($i) => $q->choices[$i], $order),
                    'choice_indexes' => $order,
                ];
            })->values(),
            'settings' => [
                'time_limit_seconds' => $settings['time_limit_minutes'] !== null ? $settings['time_limit_minutes'] * 60 : null,
                'remaining_seconds' => $attempt->deadline_at ? (int) max(0, ceil(now()->diffInSeconds($attempt->deadline_at, false))) : null,
                // BKT mastery level that chose the questions (null = teacher's mix).
                'mastery_level' => $attempt->mastery_level,
                'max_attempts' => $settings['max_attempts'],
                'attempts_used' => $attemptsUsed,
                'show_answers' => $settings['show_answers'],
            ],
            'generated_at' => $quiz->generated_at,
            'model' => $quiz->model,
            'cached' => $cached,
            'request_id' => $requestId,
        ];
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

    /**
     * Class-level professor actions (mirrors ClassPostController) are
     * owner-only — not subject-collaborator-inclusive like Subject/Module
     * actions are.
     */
    private function authorizeOwner(Request $request, ClassRoom $class): void
    {
        abort_unless($class->professor_id === $request->user()->id, 404);
    }

    private function authorizePostBelongsToClass(ClassRoom $class, ClassPost $post): void
    {
        abort_unless($post->class_id === $class->id, 404);
    }
}
