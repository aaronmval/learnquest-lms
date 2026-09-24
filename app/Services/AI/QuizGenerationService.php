<?php

namespace App\Services\AI;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Models\ClassPost;
use App\Models\Quiz;
use App\Services\Documents\PdfTextExtractorService;
use App\Services\Quiz\QuizFeedbackService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class QuizGenerationService
{
    /** Character cap for lesson content sent in a single prompt (no chunking/RAG yet). */
    private const MAX_CONTENT_CHARS = 12000;

    private const ALLOWED_DIFFICULTIES = ['easy', 'medium', 'hard'];

    public function __construct(
        private LlamaService $llama,
        private PromptService $prompts,
        private PdfTextExtractorService $extractor,
        private QuizFeedbackService $feedback,
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

    private function runGenerationPipeline(ClassPost $post, ?string $feedbackContext, ?Quiz $archiving): Quiz
    {
        $log = Log::channel('ai');
        $log->info('[quiz] Starting quiz generation.', [
            'class_post_id' => $post->id,
            'regenerating' => $archiving !== null,
        ]);

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

        $absolutePath = Storage::disk('local')->path($post->attachment_path);
        $content = $this->extractor->extractText($absolutePath);

        $fullLength = strlen($content);
        $content = mb_substr($content, 0, self::MAX_CONTENT_CHARS);

        if (strlen($content) < $fullLength) {
            $log->warning('[quiz] Lesson content truncated before prompting.', [
                'class_post_id' => $post->id,
                'full_chars' => $fullLength,
                'truncated_to' => strlen($content),
            ]);
        }

        $subjectName = $subject->name;

        $messages = $this->prompts->quizGenerationMessages(
            $subjectName,
            $post->title,
            $content,
            $competencies->toArray(),
            (int) config('quiz.default_question_count', 10),
            $feedbackContext,
        );

        $result = $this->llama->chat($messages);
        $raw = $result['content'];
        $modelUsed = $result['model'];

        try {
            $validQuestions = $this->parseAndValidate($raw, $competencies);
        } catch (InvalidAiResponseException $e) {
            $log->error('[quiz] AI response failed validation — not saved.', [
                'class_post_id' => $post->id,
                'model' => $modelUsed,
                'reason' => $e->getMessage(),
                'raw_response' => Str::limit(str_replace(["\r", "\n"], ' ', $raw), 2000),
            ]);

            throw $e;
        }

        $quiz = DB::transaction(function () use ($post, $modelUsed, $validQuestions, $archiving) {
            if ($archiving) {
                $archiving->update(['archived_at' => now()]);
            }

            $quiz = Quiz::create([
                'class_post_id' => $post->id,
                'model' => $modelUsed,
                'generated_at' => now(),
            ]);

            foreach ($validQuestions as $i => $question) {
                $quiz->questions()->create([
                    'competency_id' => $question['competency_id'],
                    'question_text' => $question['question'],
                    'choices' => $question['choices'],
                    'correct_answer' => $question['correct_answer'],
                    'explanation' => $question['explanation'],
                    'difficulty' => $question['difficulty'],
                    'order_index' => $i,
                ]);
            }

            return $quiz;
        });

        $log->info('[quiz] Saved generated quiz.', [
            'class_post_id' => $post->id,
            'quiz_id' => $quiz->id,
            'model' => $modelUsed,
            'question_count' => count($validQuestions),
            'archived_quiz_id' => $archiving?->id,
        ]);

        return $quiz->load('questions');
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
