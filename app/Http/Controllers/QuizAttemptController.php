<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuizAttemptRequest;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Notifications\AlertService;
use App\Services\Quiz\AnswerReviewService;
use App\Services\Quiz\QuizAttemptService;
use App\Services\Quiz\QuizSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuizAttemptController extends Controller
{
    /**
     * Submit and grade a full quiz attempt in one request. Correctness,
     * explanations, and mastery deltas are computed here and only here —
     * the answer key is only ever returned after the student has submitted,
     * and only when the teacher shows answers or approved a review request.
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

        // The in-progress attempt opened when the quiz was started.
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

        // Answers not shown right away: keep the score and right/wrong, hide
        // the key until the teacher approves a review request.
        if (! $settings['show_answers']) {
            $result['review'] = $this->withoutAnswerKey($result['review']);
        }

        return response()->json($result + [
            'show_answers' => $settings['show_answers'],
            'answer_review' => ['status' => null],
        ], 201);
    }

    /**
     * The student left the quiz (tab switch, alt-tab, minimize). Counted on
     * the in-progress attempt; the page auto-submits at the limit.
     */
    public function focusViolation(Request $request, ClassRoom $class, ClassPost $post, QuizAttempt $attempt): JsonResponse
    {
        $this->authorizeEnrolled($request, $class);
        $this->authorizePostBelongsToClass($class, $post);
        $this->authorizeAttempt($attempt, $post, $request->user());

        abort_unless($attempt->status === 'in_progress', 409);

        $attempt->increment('focus_violations');

        return response()->json([
            'violations' => (int) $attempt->focus_violations,
            'limit' => (int) config('quiz.max_focus_violations'),
        ]);
    }

    /** Ask the teacher for permission to review this attempt's answers. */
    public function requestAnswerReview(
        Request $request,
        ClassRoom $class,
        ClassPost $post,
        QuizAttempt $attempt,
        QuizSettingsService $settingsService,
        AnswerReviewService $answerReviews,
        AlertService $alerts,
    ): JsonResponse {
        $this->authorizeEnrolled($request, $class);
        $this->authorizePostBelongsToClass($class, $post);
        $this->authorizeAttempt($attempt, $post, $request->user());
        abort_if($attempt->status === 'in_progress', 409);

        if ($settingsService->for($post)['show_answers']) {
            return response()->json(['status' => 'approved']);
        }

        $before = $answerReviews->statusFor($attempt);
        $review = $answerReviews->request($attempt, $post);

        if ($before !== $review->status) {
            $alerts->answerReviewRequested($request->user(), $post);
        }

        return response()->json(['status' => $review->status], $before === null ? 201 : 200);
    }

    /**
     * A submitted attempt's score and review. The answer key and
     * explanations are included only when the student may see them.
     */
    public function review(
        Request $request,
        ClassRoom $class,
        ClassPost $post,
        QuizAttempt $attempt,
        QuizAttemptService $attempts,
        QuizSettingsService $settingsService,
        AnswerReviewService $answerReviews,
    ): JsonResponse {
        $this->authorizeEnrolled($request, $class);
        $this->authorizePostBelongsToClass($class, $post);
        $this->authorizeAttempt($attempt, $post, $request->user());
        abort_if($attempt->status === 'in_progress', 409);

        $settings = $settingsService->for($post);
        $canView = $answerReviews->canViewAnswers($attempt, $settings);
        $result = $attempts->reviewFor($attempt);

        if (! $canView) {
            $result['review'] = $this->withoutAnswerKey($result['review']);
        }

        return response()->json($result + [
            'attempt_id' => $attempt->id,
            'lesson_title' => $post->title,
            'subject' => $class->subject ?? $class->name ?? 'Science',
            'section' => $class->section,
            'answers_visible' => $canView,
            'answer_review' => ['status' => $settings['show_answers'] ? 'approved' : $answerReviews->statusFor($attempt)],
        ]);
    }

    /**
     * The student's latest submitted attempt on this lesson (any quiz
     * version) and whether its answers can be reviewed — for the lesson
     * page's quiz card.
     */
    public function latest(Request $request, ClassRoom $class, ClassPost $post, QuizSettingsService $settingsService, AnswerReviewService $answerReviews): JsonResponse
    {
        $this->authorizeEnrolled($request, $class);
        $this->authorizePostBelongsToClass($class, $post);

        $attempt = QuizAttempt::query()
            ->whereHas('quiz', fn ($q) => $q->where('class_post_id', $post->id))
            ->where('student_id', $request->user()->id)
            ->where('status', '!=', 'in_progress')
            ->with('answerReviewRequest')
            ->latest('submitted_at')
            ->latest('id')
            ->first();

        if (! $attempt) {
            return response()->json(['attempt' => null]);
        }

        $settings = $settingsService->for($post);

        return response()->json(['attempt' => [
            'id' => $attempt->id,
            'score_correct' => $attempt->score_correct,
            'score_total' => $attempt->score_total,
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'answers_visible' => $answerReviews->canViewAnswers($attempt, $settings),
            'answer_review_status' => $settings['show_answers'] ? 'approved' : $answerReviews->statusFor($attempt),
        ]]);
    }

    private function withoutAnswerKey(array $review): array
    {
        return array_map(
            fn ($entry) => array_merge($entry, ['correct_index' => null, 'explanation' => null]),
            $review,
        );
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
        abort_unless($attempt->quiz?->class_post_id === $post->id, 404);
    }
}
