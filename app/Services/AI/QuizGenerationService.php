<?php

namespace App\Services\AI;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Models\ClassPost;
use App\Models\Quiz;
use App\Models\Subject;
use App\Services\Documents\LessonContentService;
use App\Services\Documents\PdfTextExtractorService;
use App\Services\Documents\StoredFile;
use App\Services\Learning\AdaptiveQuizService;
use App\Services\Quiz\QuizFeedbackService;
use App\Services\Quiz\QuizSettingsService;
use App\Services\Quiz\QuizTrainingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class QuizGenerationService
{
    /** Character cap for lesson content sent in a single request. */
    private const MAX_CONTENT_CHARS = 12000;

    private const ALLOWED_DIFFICULTIES = ['easy', 'medium', 'hard'];

    /** Existing questions listed in a top-up prompt so the model avoids repeating them. */
    private const MAX_AVOID_QUESTIONS = 15;

    public function __construct(
        private LlamaService $llama,
        private PromptService $prompts,
        private PdfTextExtractorService $extractor,
        private QuizFeedbackService $feedback,
        private QuizSettingsService $settings,
        private QuizTrainingService $training,
        private LessonContentService $lessonContent,
        private AdaptiveQuizService $adaptiveQuiz,
    ) {}

    public function generateForPost(ClassPost $post): Quiz
    {
        return $this->runGenerationPipeline($post, feedbackContext: null, archiving: null);
    }

    /**
     * Archives the lesson's current quiz and generates a new version,
     * informed by student feedback on the version being replaced. Past
     * QuizAttempt/QuizAnswer rows keep pointing at the archived quiz_id —
     * nothing is deleted, so attempt history and BKT mastery are untouched.
     */
    public function regenerateForPost(ClassPost $post): Quiz
    {
        $current = $post->quiz;

        if (! $current) {
            throw new RuntimeException('This lesson has no quiz yet to regenerate. Generate one first.');
        }

        $feedbackContext = $this->feedback->promptContext($current);

        return $this->runGenerationPipeline($post, $feedbackContext, archiving: $current);
    }

    /**
     * Generate new questions for the current quiz until it has the
     * teacher's question count of active (non-rejected) questions again —
     * i.e. replace rejected questions, using the difficulties the teacher's
     * mix is now missing. Rejected rows are kept (hidden), so past attempts
     * and the AI-training data stay intact.
     *
     * @return int number of questions added
     */
    public function topUp(ClassPost $post): int
    {
        $quiz = $post->quiz;

        if (! $quiz) {
            throw new RuntimeException('This lesson has no quiz yet. Generate one first.');
        }

        $pool = $this->adaptiveQuiz->poolAllocation($this->settings->for($post));
        $active = $quiz->activeQuestions()->get();
        $needed = array_sum($pool) - $active->count();

        if ($needed <= 0) {
            throw new RuntimeException('This quiz already has its full number of questions.');
        }

        [$subject, $competencies, $content] = $this->prepare($post);

        $counts = $this->missingDifficulties($pool, $active->countBy('difficulty')->all(), $needed);

        $generated = $this->generateQuestions($post, $subject, $competencies, $content, $counts, null, $active->pluck('question_text')->all());

        DB::transaction(function () use ($quiz, $generated) {
            $next = (int) $quiz->questions()->max('order_index') + 1;

            foreach ($generated['questions'] as $i => $question) {
                $this->storeQuestion($quiz, $question, $next + $i);
            }
        });

        Log::channel('ai')->info('[quiz] Topped up quiz with replacement questions.', [
            'class_post_id' => $post->id,
            'quiz_id' => $quiz->id,
            'added' => count($generated['questions']),
            'model' => $generated['model'],
        ]);

        return count($generated['questions']);
    }

    private function runGenerationPipeline(ClassPost $post, ?string $feedbackContext, ?Quiz $archiving): Quiz
    {
        $log = Log::channel('ai');
        $log->info('[quiz] Starting quiz generation.', [
            'class_post_id' => $post->id,
            'regenerating' => $archiving !== null,
        ]);

        [$subject, $competencies, $content] = $this->prepare($post);

        // Adaptive quizzes need a bank big enough to serve every mastery
        // level its own mix; otherwise this is the teacher's allocation.
        $counts = $this->adaptiveQuiz->poolAllocation($this->settings->for($post));

        $generated = $this->generateQuestions($post, $subject, $competencies, $content, $counts, $feedbackContext);

        $quiz = DB::transaction(function () use ($post, $generated, $archiving) {
            if ($archiving) {
                $archiving->update(['archived_at' => now()]);
            }

            $quiz = Quiz::create([
                'class_post_id' => $post->id,
                'model' => $generated['model'],
                'generated_at' => now(),
            ]);

            foreach ($generated['questions'] as $i => $question) {
                $this->storeQuestion($quiz, $question, $i);
            }

            return $quiz;
        });

        $log->info('[quiz] Saved generated quiz.', [
            'class_post_id' => $post->id,
            'quiz_id' => $quiz->id,
            'model' => $generated['model'],
            'question_count' => count($generated['questions']),
            'archived_quiz_id' => $archiving?->id,
        ]);

        return $quiz->load('questions');
    }

    /**
     * @return array{0: Subject, 1: Collection, 2: string}
     */
    private function prepare(ClassPost $post): array
    {
        if (! $post->attachment_path) {
            throw new RuntimeException('This lesson has no attachment to generate a quiz from.');
        }

        $subject = $post->classRoom?->parentSubject;

        if (! $subject) {
            throw new RuntimeException('This class is not linked to a Subject, so no competencies are available to tag quiz questions with.');
        }

        $competencies = $subject->competencies()->get(['id', 'name', 'description']);

        if ($competencies->isEmpty()) {
            throw new RuntimeException('No competencies have been defined for this subject yet.');
        }

        $content = StoredFile::withLocalPath(
            $post->attachment_path,
            fn (string $path) => $this->extractor->extractText($path),
        );

        return [$subject, $competencies, $content];
    }

    /**
     * Generate questions with exact difficulty counts. Up to
     * `quiz.questions_per_batch` questions go in one request (with
     * LlamaService's retries and fallback); larger quizzes are split into
     * batches sent in parallel, each covering a different section of the
     * lesson, with per-batch fallback to the secondary model.
     *
     * @param  array{easy:int, medium:int, hard:int}  $counts
     * @param  array<int, string>  $avoid  existing question texts not to repeat
     * @return array{questions: array<int, array>, model: string}
     */
    private function generateQuestions(ClassPost $post, Subject $subject, Collection $competencies, string $content, array $counts, ?string $feedbackContext, array $avoid = []): array
    {
        $total = array_sum($counts);
        $batches = $this->batches($counts);
        $batchCount = count($batches);

        $fullLength = mb_strlen($content);
        $content = mb_substr($content, 0, self::MAX_CONTENT_CHARS * $batchCount);

        if (mb_strlen($content) < $fullLength) {
            Log::channel('ai')->warning('[quiz] Lesson content truncated before prompting.', [
                'class_post_id' => $post->id,
                'full_chars' => $fullLength,
                'truncated_to' => mb_strlen($content),
            ]);
        }

        $sections = $this->lessonContent->sections($content, $batchCount);
        $trainingContext = $this->training->promptContext($subject);
        $limits = array_map('intval', (array) config('services.routeway.quiz', []));

        $conversations = [];
        foreach ($batches as $i => $batchCounts) {
            $conversations[$i] = $this->prompts->quizGenerationMessages(
                $subject->name,
                $post->title,
                $sections[$i] ?? $content,
                $competencies->toArray(),
                array_sum($batchCounts),
                $feedbackContext,
                $batchCounts,
                $trainingContext,
                $this->scopeNote($i, $batchCount, $avoid),
            );
        }

        if ($batchCount === 1) {
            $result = $this->llama->chat($conversations[0], $limits);
            $questions = $this->validatedOrLogged($result['content'], $competencies, $post, $result['model']);
            $model = $result['model'];
        } else {
            $results = $this->llama->chatConcurrent(
                $conversations,
                $limits,
                fn (string $raw) => $this->validatedOrLogged($raw, $competencies, $post, null),
            );
            $questions = array_merge(...array_column($results, 'value'));
            $model = implode(',', array_unique(array_column($results, 'model')));
        }

        $questions = $this->withoutDuplicates($questions, $avoid);

        if (empty($questions)) {
            throw new InvalidAiResponseException('No new, non-duplicate questions passed validation.');
        }

        return ['questions' => array_slice($questions, 0, $total), 'model' => $model];
    }

    /**
     * Deal the difficulty slots round-robin into batches so every batch
     * gets a similar mix and the totals stay exact.
     *
     * @param  array{easy:int, medium:int, hard:int}  $counts
     * @return array<int, array{easy:int, medium:int, hard:int}>
     */
    private function batches(array $counts): array
    {
        $total = array_sum($counts);
        $perBatch = max(1, (int) config('quiz.questions_per_batch', 8));
        $batchCount = max(1, (int) ceil($total / $perBatch));

        $batches = array_fill(0, $batchCount, ['easy' => 0, 'medium' => 0, 'hard' => 0]);
        $slot = 0;

        foreach (self::ALLOWED_DIFFICULTIES as $difficulty) {
            for ($n = 0; $n < ($counts[$difficulty] ?? 0); $n++) {
                $batches[$slot % $batchCount][$difficulty]++;
                $slot++;
            }
        }

        return $batches;
    }

    private function scopeNote(int $index, int $batchCount, array $avoid): ?string
    {
        $notes = [];

        if ($batchCount > 1) {
            $n = $index + 1;
            $notes[] = "This request is batch {$n} of {$batchCount}: the lesson content below is only one section of the lesson. "
                .'Write questions about this section only; other batches cover the rest.';
        }

        if (! empty($avoid)) {
            $list = collect($avoid)->take(self::MAX_AVOID_QUESTIONS)->map(fn ($q) => '- '.Str::limit($q, 150))->implode("\n");
            $notes[] = "The quiz already contains these questions. Do NOT repeat or rephrase them — test different ideas:\n{$list}";
        }

        return empty($notes) ? null : implode("\n\n", $notes);
    }

    /**
     * Difficulties to generate when topping up: what the teacher's mix
     * calls for minus what the quiz still has, adjusted to exactly $needed.
     *
     * @param  array{easy:int, medium:int, hard:int}  $target
     * @param  array<string, int>  $current
     * @return array{easy:int, medium:int, hard:int}
     */
    private function missingDifficulties(array $target, array $current, int $needed): array
    {
        $missing = [];
        foreach (self::ALLOWED_DIFFICULTIES as $difficulty) {
            $missing[$difficulty] = max(0, $target[$difficulty] - ($current[$difficulty] ?? 0));
        }

        while (array_sum($missing) > $needed) {
            $missing[array_search(max($missing), $missing, true)]--;
        }

        while (array_sum($missing) < $needed) {
            $missing['medium']++;
        }

        return $missing;
    }

    /**
     * Drop questions whose text repeats another generated question or an
     * existing one (case/punctuation-insensitive).
     */
    private function withoutDuplicates(array $questions, array $existing): array
    {
        $normalize = fn (string $text) => preg_replace('/[^\p{L}\p{N}]+/u', '', Str::lower($text));
        $seen = array_fill_keys(array_map($normalize, $existing), true);

        return array_values(array_filter($questions, function ($question) use (&$seen, $normalize) {
            $key = $normalize($question['question']);

            if (isset($seen[$key])) {
                return false;
            }

            return $seen[$key] = true;
        }));
    }

    private function storeQuestion(Quiz $quiz, array $question, int $orderIndex): void
    {
        $quiz->questions()->create([
            'competency_id' => $question['competency_id'],
            'question_text' => $question['question'],
            'choices' => $question['choices'],
            'correct_answer' => $question['correct_answer'],
            'explanation' => $question['explanation'],
            'difficulty' => $question['difficulty'],
            'order_index' => $orderIndex,
        ]);
    }

    private function validatedOrLogged(string $raw, Collection $competencies, ClassPost $post, ?string $model): array
    {
        try {
            return $this->parseAndValidate($raw, $competencies);
        } catch (InvalidAiResponseException $e) {
            Log::channel('ai')->error('[quiz] AI response failed validation — not saved.', [
                'class_post_id' => $post->id,
                'model' => $model,
                'reason' => $e->getMessage(),
                'raw_response' => Str::limit(str_replace(["\r", "\n"], ' ', $raw), 2000),
            ]);

            throw $e;
        }
    }

    /**
     * Parse the AI's JSON response and drop any question that fails
     * validation, keeping the rest — mirrors SummarizationService's lenient
     * filtering of key_points rather than rejecting the whole response.
     * Only throws if nothing usable survives (never save unvalidated output).
     *
     * @return array<int, array{question:string, choices:array<int,string>, correct_answer:string, explanation:string, competency_id:int, difficulty:string}>
     */
    private function parseAndValidate(string $raw, Collection $competencies): array
    {
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            // The model may wrap the JSON in prose or markdown fences despite
            // instructions not to — fall back to extracting the outermost object.
            if (preg_match('/\{.*\}/s', $raw, $matches)) {
                $decoded = json_decode($matches[0], true);
            }
        }

        if (! is_array($decoded)) {
            throw new InvalidAiResponseException('AI response was not valid JSON.');
        }

        $rawQuestions = $decoded['questions'] ?? null;

        if (! is_array($rawQuestions) || count($rawQuestions) === 0) {
            throw new InvalidAiResponseException('AI response is missing a valid questions array.');
        }

        $allowedCompetencyIds = $competencies->pluck('id')->all();
        $minChoices = (int) config('quiz.min_choices', 2);
        $maxChoices = (int) config('quiz.max_choices', 6);
        $maxQuestions = (int) config('quiz.max_questions_per_batch', 15);

        $valid = [];

        foreach ($rawQuestions as $item) {
            $question = $this->validateQuestion($item, $allowedCompetencyIds, $minChoices, $maxChoices);

            if ($question !== null) {
                $valid[] = $question;
            }
        }

        if (count($valid) === 0) {
            throw new InvalidAiResponseException('No questions in the AI response passed validation.');
        }

        return array_slice($valid, 0, $maxQuestions);
    }

    /**
     * @param  array<int>  $allowedCompetencyIds
     * @return array{question:string, choices:array<int,string>, correct_answer:string, explanation:string, competency_id:int, difficulty:string}|null
     */
    private function validateQuestion(mixed $item, array $allowedCompetencyIds, int $minChoices, int $maxChoices): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $questionText = $item['question'] ?? null;
        if (! is_string($questionText) || trim($questionText) === '') {
            return null;
        }

        $rawChoices = $item['choices'] ?? null;
        if (! is_array($rawChoices)) {
            return null;
        }

        $choices = array_values(array_filter(array_map(
            fn ($choice) => is_string($choice) ? trim($choice) : null,
            $rawChoices,
        ), fn ($choice) => ! empty($choice)));

        if (count($choices) < $minChoices || count($choices) > $maxChoices) {
            return null;
        }

        if (count($choices) !== count(array_unique($choices))) {
            return null;
        }

        $correctAnswer = $item['correct_answer'] ?? null;
        if (! is_string($correctAnswer) || trim($correctAnswer) === '') {
            return null;
        }
        $correctAnswer = trim($correctAnswer);

        if (! in_array($correctAnswer, $choices, true)) {
            return null;
        }

        $competencyId = $item['competency_id'] ?? null;
        if (! is_int($competencyId) && ! (is_string($competencyId) && ctype_digit($competencyId))) {
            return null;
        }
        $competencyId = (int) $competencyId;

        if (! in_array($competencyId, $allowedCompetencyIds, true)) {
            return null;
        }

        $difficulty = $item['difficulty'] ?? null;
        if (! is_string($difficulty) || ! in_array($difficulty, self::ALLOWED_DIFFICULTIES, true)) {
            return null;
        }

        $explanation = $item['explanation'] ?? null;
        if (! is_string($explanation) || trim($explanation) === '') {
            return null;
        }

        return [
            'question' => trim($questionText),
            'choices' => $choices,
            'correct_answer' => $correctAnswer,
            'explanation' => trim($explanation),
            'competency_id' => $competencyId,
            'difficulty' => $difficulty,
        ];
    }
}
