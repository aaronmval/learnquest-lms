<?php

namespace App\Http\Controllers;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Exceptions\AI\LlamaApiException;
use App\Http\Requests\ReviewQuizQuestionRequest;
use App\Http\Requests\UpdateQuizSettingsRequest;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Competency;
use App\Models\QuizQuestion;
use App\Models\QuizQuestionReview;
use App\Services\AI\QuizGenerationService;
use App\Services\Learning\AdaptiveQuizService;
use App\Services\Quiz\QuizFeedbackService;
use App\Services\Quiz\QuizSettingsService;
use App\Services\Quiz\QuizTrainingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Quiz & AI Setup page: per-lesson quiz settings, optional review of the
 * AI-generated questions (which trains future generation for the subject),
 * and AI accuracy metrics. Open to whoever manages the section: its
 * creator, or the owner/collaborators of the subject it belongs to
 * (ClassRoom::isManagedBy).
 */
class QuizStudioController extends Controller
{
    /**
     * The active sections the professor manages, with their PDF lessons and quiz status.
     */
    public function lessons(Request $request, QuizSettingsService $settingsService, AdaptiveQuizService $adaptiveQuiz): JsonResponse
    {
        $classes = ClassRoom::query()
            ->managedBy($request->user())
            ->whereNull('archived_at')
            ->with('parentSubject')
            ->orderBy('name')
            ->orderBy('section')
            ->get();

        $posts = ClassPost::query()
            ->whereIn('class_id', $classes->pluck('id'))
            ->where('type', 'lesson')
            ->whereNotNull('attachment_path')
            ->with(['quizSetting', 'quiz.questions.review'])
            ->latest('id')
            ->get()
            ->filter(fn (ClassPost $post) => Str::endsWith(Str::lower($post->attachment_name ?: $post->attachment_path), '.pdf'))
            ->groupBy('class_id');

        return response()->json([
            'classes' => $classes->map(fn (ClassRoom $class) => [
                'id' => $class->id,
                'label' => $this->classLabel($class),
                'has_subject' => $class->parentSubject !== null,
                'lessons' => ($posts->get($class->id) ?? collect())->map(function (ClassPost $post) use ($settingsService, $adaptiveQuiz) {
                    $questions = $post->quiz?->questions ?? collect();
                    $rejected = $questions->filter(fn ($q) => $q->review?->verdict === QuizQuestionReview::REJECTED)->count();

                    return [
                        'id' => $post->id,
                        'title' => $post->title,
                        'quarter' => $post->quarter,
                        'has_quiz' => $post->quiz !== null,
                        'question_count' => $questions->count() - $rejected,
                        // Size of the question bank (bigger than the per-student
                        // count when the quiz adapts to mastery).
                        'target_count' => array_sum($adaptiveQuiz->poolAllocation($settingsService->for($post))),
                        'reviewed' => $questions->filter(fn ($q) => $q->review !== null)->count(),
                        'rejected' => $rejected,
                        'settings_saved' => $post->quizSetting !== null,
                    ];
                })->values(),
            ])->values(),
        ]);
    }

    /**
     * Everything the page needs for one lesson: settings, questions with
     * answers and reviews, student results per question, student feedback.
     */
    public function show(Request $request, ClassRoom $class, ClassPost $post, QuizSettingsService $settingsService, QuizTrainingService $training, QuizFeedbackService $feedback, AdaptiveQuizService $adaptiveQuiz): JsonResponse
    {
        $this->authorizeManagedLesson($request, $class, $post);

        $quiz = $post->quiz;
        $questions = $quiz ? $quiz->questions()->with(['competency', 'aiCompetency', 'review'])->get() : collect();
        $stats = $training->questionStats($questions);
        $settings = $settingsService->for($post);

        return response()->json([
            'lesson' => ['id' => $post->id, 'title' => $post->title, 'class_label' => $this->classLabel($class)],
            'settings' => $settings,
            'allocation' => $settingsService->allocate($settings['question_count'], $settings['difficulty_mix']),
            'pool' => $adaptiveQuiz->poolAllocation($settings),
            // The subject's competencies, for checking each question's tag.
            'competencies' => ($class->parentSubject?->competencies ?? collect())
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
                ->values(),
            'limits' => [
                'min_question_count' => (int) config('quiz.min_question_count'),
                'max_question_count' => (int) config('quiz.max_question_count'),
                'max_time_limit_minutes' => (int) config('quiz.max_time_limit_minutes'),
                'reasons' => QuizTrainingService::REASON_LABELS,
                'min_responses' => (int) config('quiz.min_responses_for_calibration'),
                'adaptive_shift' => (int) config('quiz.adaptive_shift'),
            ],
            'quiz' => $quiz ? [
                'id' => $quiz->id,
                'generated_at' => $quiz->generated_at?->toIso8601String(),
                'model' => $quiz->model,
                'attempts' => $quiz->attempts()->where('status', '!=', 'in_progress')->count(),
                'feedback' => $feedback->summaryForQuiz($quiz),
                'feedback_breakdown' => $feedback->breakdownForQuiz($quiz),
            ] : null,
            'questions' => $questions->map(fn (QuizQuestion $q) => $this->questionPayload($q, $stats[$q->id] ?? null))->values(),
        ]);
    }

    public function updateSettings(UpdateQuizSettingsRequest $request, ClassRoom $class, ClassPost $post, QuizSettingsService $settingsService, AdaptiveQuizService $adaptiveQuiz): JsonResponse
    {
        $this->authorizeManagedLesson($request, $class, $post);

        $settingsService->save($post, $request->user(), $request->validated());
        $settings = $settingsService->for($post->fresh());

        return response()->json([
            'settings' => $settings,
            'allocation' => $settingsService->allocate($settings['question_count'], $settings['difficulty_mix']),
            'pool' => $adaptiveQuiz->poolAllocation($settings),
        ]);
    }

    /**
     * Generate the lesson's quiz with the current settings — or, if it
     * already has one, archive it and generate a new version (past
     * attempts stay on the archived version).
     */
    public function generate(Request $request, ClassRoom $class, ClassPost $post, QuizGenerationService $generator): JsonResponse
    {
        $this->authorizeManagedLesson($request, $class, $post);

        try {
            $quiz = $post->quiz ? $generator->regenerateForPost($post) : $generator->generateForPost($post);
        } catch (Throwable $e) {
            return $this->generationError($e, $post, "We couldn't generate the quiz.");
        }

        return response()->json(['quiz_id' => $quiz->id, 'question_count' => $quiz->questions->count()], 201);
    }

    /**
     * Replace rejected questions: generate new ones until the quiz is back
     * to the teacher's question count.
     */
    public function topUp(Request $request, ClassRoom $class, ClassPost $post, QuizGenerationService $generator): JsonResponse
    {
        $this->authorizeManagedLesson($request, $class, $post);

        try {
            $added = $generator->topUp($post);
        } catch (Throwable $e) {
            return $this->generationError($e, $post, "We couldn't generate replacement questions.");
        }

        return response()->json(['added' => $added], 201);
    }

    /**
     * Approve or reject a question, optionally correcting its difficulty.
     * The review is the labeled example future generation learns from.
     */
    public function review(ReviewQuizQuestionRequest $request, ClassRoom $class, ClassPost $post, QuizQuestion $question, QuizTrainingService $training): JsonResponse
    {
        $this->authorizeManagedQuestion($request, $class, $post, $question);
        $data = $request->validated();

        $review = DB::transaction(function () use ($question, $data, $request) {
            $existing = $question->review;
            // The model's original label, captured before any teacher correction.
            $aiDifficulty = $existing?->ai_difficulty ?? $question->difficulty;
            $teacherDifficulty = $data['teacher_difficulty'] ?? null;

            if ($teacherDifficulty === $aiDifficulty) {
                $teacherDifficulty = null;
            }

            $review = QuizQuestionReview::updateOrCreate(
                ['quiz_question_id' => $question->id],
                [
                    'professor_id' => $request->user()->id,
                    'verdict' => $data['verdict'],
                    'reason' => $data['verdict'] === QuizQuestionReview::REJECTED ? ($data['reason'] ?? 'other') : null,
                    'comment' => filled($data['comment'] ?? null) ? trim($data['comment']) : null,
                    'ai_difficulty' => $aiDifficulty,
                    'teacher_difficulty' => $teacherDifficulty,
                ],
            );

            $question->update(['difficulty' => $teacherDifficulty ?? $aiDifficulty]);

            return $review;
        });

        $question = $question->fresh(['competency', 'review']);

        return response()->json([
            'question' => $this->questionPayload($question, $training->questionStats(collect([$question]))[$question->id] ?? null),
            'review_id' => $review->id,
        ]);
    }

    /**
     * Correct the competency a question is tagged with. The AI's original
     * tag is kept alongside it. Only future answers are affected: responses
     * already recorded keep the competency they were graded under, so stored
     * BKT mastery is not rewritten.
     */
    public function updateCompetency(Request $request, ClassRoom $class, ClassPost $post, QuizQuestion $question, QuizTrainingService $training): JsonResponse
    {
        $this->authorizeManagedQuestion($request, $class, $post, $question);

        $validated = $request->validate(['competency_id' => ['required', 'integer']]);
        $competencyId = (int) $validated['competency_id'];

        // Only competencies of this section's own subject are valid tags.
        abort_unless(
            $class->subject_id !== null
                && Competency::where('id', $competencyId)->where('subject_id', $class->subject_id)->exists(),
            422,
            'That competency does not belong to this subject.',
        );

        if ($competencyId !== $question->competency_id) {
            $original = $question->ai_competency_id ?? $question->competency_id;

            $question->update([
                'competency_id' => $competencyId,
                'ai_competency_id' => $competencyId === $original ? null : $original,
            ]);
        }

        $question = $question->fresh(['competency', 'aiCompetency', 'review']);

        return response()->json([
            'question' => $this->questionPayload($question, $training->questionStats(collect([$question]))[$question->id] ?? null),
        ]);
    }

    /** Undo a review: the question returns to its AI label and becomes active again. */
    public function clearReview(Request $request, ClassRoom $class, ClassPost $post, QuizQuestion $question, QuizTrainingService $training): JsonResponse
    {
        $this->authorizeManagedQuestion($request, $class, $post, $question);

        DB::transaction(function () use ($question) {
            if ($review = $question->review) {
                $question->update(['difficulty' => $review->ai_difficulty]);
                $review->delete();
            }
        });

        $question = $question->fresh(['competency', 'review']);

        return response()->json([
            'question' => $this->questionPayload($question, $training->questionStats(collect([$question]))[$question->id] ?? null),
        ]);
    }

    /**
     * AI accuracy metrics for a section's subject (reviews + student results).
     */
    public function training(Request $request, QuizTrainingService $training): JsonResponse
    {
        $validated = $request->validate(['class_id' => ['required', 'integer']]);

        $class = ClassRoom::with('parentSubject')->find($validated['class_id']);
        abort_unless($class && $class->isManagedBy($request->user()), 404);

        if (! $class->parentSubject) {
            return response()->json(['subject' => null, 'metrics' => null]);
        }

        return response()->json([
            'subject' => ['id' => $class->parentSubject->id, 'name' => $class->parentSubject->name],
            'metrics' => $training->metrics($class->parentSubject),
        ]);
    }

    private function questionPayload(QuizQuestion $question, ?array $stats): array
    {
        $review = $question->review;

        return [
            'id' => $question->id,
            'text' => $question->question_text,
            'choices' => $question->choices,
            'correct_index' => array_search($question->correct_answer, $question->choices, true),
            'explanation' => $question->explanation,
            'competency' => $question->competency?->name,
            'competency_id' => $question->competency_id,
            // Set only when the teacher changed the AI's tag.
            'ai_competency' => $question->aiCompetency?->name,
            'difficulty' => $question->difficulty,
            'ai_difficulty' => $review?->ai_difficulty ?? $question->difficulty,
            'review' => $review ? [
                'verdict' => $review->verdict,
                'reason' => $review->reason,
                'comment' => $review->comment,
                'teacher_difficulty' => $review->teacher_difficulty,
            ] : null,
            'stats' => $stats,
        ];
    }

    private function generationError(Throwable $e, ClassPost $post, string $friendly): JsonResponse
    {
        $requestId = (string) Str::uuid();

        Log::channel('ai')->error('[quiz-studio] Generation failed.', [
            'request_id' => $requestId,
            'class_post_id' => $post->id,
            'exception' => get_class($e),
            'error' => $e->getMessage(),
        ]);

        [$message, $status] = match (true) {
            $e instanceof LlamaApiException => ['The AI quiz service is temporarily unavailable. Please try again shortly.', 502],
            $e instanceof InvalidAiResponseException => ['The AI returned questions that failed validation. Please try again.', 502],
            // Our own RuntimeExceptions carry teacher-facing messages
            // (no competencies, unreadable PDF, quiz already full…).
            get_class($e) === RuntimeException::class => [$e->getMessage(), 422],
            default => [$friendly.' Please try again.', 500],
        };

        return response()->json(['message' => $message, 'request_id' => $requestId], $status);
    }

    private function classLabel(ClassRoom $class): string
    {
        $subject = $class->subject ?: ($class->parentSubject?->name ?? $class->name);

        return $class->section ? "{$subject} - {$class->section}" : $subject;
    }

    private function authorizeManagedLesson(Request $request, ClassRoom $class, ClassPost $post): void
    {
        abort_unless($class->isManagedBy($request->user()), 404);
        abort_unless($post->class_id === $class->id && $post->type === 'lesson', 404);
    }

    /** The question must belong to one of this lesson's quiz versions. */
    private function authorizeManagedQuestion(Request $request, ClassRoom $class, ClassPost $post, QuizQuestion $question): void
    {
        $this->authorizeManagedLesson($request, $class, $post);
        abort_unless($question->quiz?->class_post_id === $post->id, 404);
    }
}
