<?php

namespace App\Services\AI;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Exceptions\AI\LlamaApiException;
use App\Models\ClassRoom;
use App\Models\QuizAnswer;
use App\Models\User;
use App\Services\Bkt\BayesianKnowledgeTracingService;
use App\Services\Learning\AdaptiveLearningService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Personalized "AI Insights" for a student in a class. BKT supplies the
 * mastery numbers, AdaptiveLearningService decides levels/weaknesses, and
 * Llama only phrases that data as study advice. If Llama is unavailable or
 * returns invalid output, rule-based insights built from the same BKT data
 * are returned instead.
 */
class InsightService
{
    private const INSIGHT_TYPES = ['weakness', 'strength', 'next_step'];

    private const MAX_INSIGHTS = 4;

    private const MAX_TEXT_CHARS = 300;

    private const MAX_RECENT_MISTAKES = 5;

    private const CACHE_TTL_SECONDS = 86400;

    public function __construct(
        private LlamaService $llama,
        private PromptService $prompts,
        private BayesianKnowledgeTracingService $bkt,
        private AdaptiveLearningService $adaptive,
    ) {
    }

    /**
     * @return array{
     *     overall: ?array{mastery: float, level: string},
     *     weaknesses: array<int, array{id:int, name:string, mastery:float, level:string}>,
     *     strengths: array<int, array{id:int, name:string, mastery:float, level:string}>,
     *     insights: array<int, array{type:string, text:string, competency_name:?string}>,
     *     source: string,
     *     generated_at: ?string
     * }
     */
    public function insightsForClass(User $student, ClassRoom $class, bool $refresh = false): array
    {
        $subject = $class->parentSubject;
        $competencies = $subject ? $subject->competencies()->get() : collect();
        $profile = $this->adaptive->competencyProfile($student, $competencies);

        $assessed = array_filter($profile, fn ($c) => $c['assessed']);

        if (empty($assessed)) {
            return [
                'overall' => null,
                'weaknesses' => [],
                'strengths' => [],
                'insights' => [],
                'source' => 'empty',
                'generated_at' => null,
            ];
        }

        $cacheKey = $this->cacheKey($student, $class, $profile);

        if (! $refresh && ($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $overallMastery = (float) $this->bkt->averageMasteryForCompetencies($student, $competencies);
        $overall = ['mastery' => $overallMastery, 'level' => $this->adaptive->classify($overallMastery)];
        $weaknesses = $this->adaptive->weaknesses($profile);
        $strengths = $this->adaptive->strengths($profile);
        $namesById = collect($profile)->pluck('name', 'id')->all();

        $source = 'ai';

        try {
            $insights = $this->generateAiInsights(
                $student,
                $class->subject ?: ($subject->name ?? 'Science'),
                $overall,
                $profile,
                $weaknesses,
                $namesById,
            );
        } catch (LlamaApiException|InvalidAiResponseException $e) {
            Log::channel('ai')->warning('[insights] AI insights unavailable — using rule-based insights.', [
                'student_id' => $student->id,
                'class_id' => $class->id,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            $source = 'rules';
            $insights = $this->ruleBasedInsights($weaknesses, $strengths);
        }

        $result = [
            'overall' => $overall,
            'weaknesses' => array_map(fn ($c) => $this->publicCompetency($c), $weaknesses),
            'strengths' => array_map(fn ($c) => $this->publicCompetency($c), $strengths),
            'insights' => $insights,
            'source' => $source,
            'generated_at' => now()->toIso8601String(),
        ];

        // Only cache AI output, so a transient outage doesn't pin the
        // fallback until the student's mastery next changes.
        if ($source === 'ai') {
            Cache::put($cacheKey, $result, self::CACHE_TTL_SECONDS);
        }

        return $result;
    }

    /**
     * @throws LlamaApiException
     * @throws InvalidAiResponseException
     */
    private function generateAiInsights(
        User $student,
        string $subjectName,
        array $overall,
        array $profile,
        array $weaknesses,
        array $namesById,
    ): array {
        $messages = $this->prompts->studentInsightMessages(
            $subjectName,
            $overall['mastery'],
            $overall['level'],
            $profile,
            $this->recentMistakes($student, array_column($weaknesses, 'id'), $namesById),
        );

        $result = $this->llama->chat($messages);

        try {
            return $this->parseAndValidate($result['content'], $namesById);
        } catch (InvalidAiResponseException $e) {
            Log::channel('ai')->error('[insights] AI response failed validation.', [
                'student_id' => $student->id,
                'model' => $result['model'],
                'reason' => $e->getMessage(),
                'raw_response' => Str::limit(str_replace(["\r", "\n"], ' ', $result['content']), 2000),
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<int, string>  $namesById
     * @return array<int, array{type:string, text:string, competency_name:?string}>
     */
    public function parseAndValidate(string $raw, array $namesById): array
    {
        $decoded = json_decode($raw, true);

        if (! is_array($decoded) && preg_match('/\{.*\}/s', $raw, $matches)) {
            $decoded = json_decode($matches[0], true);
        }

        if (! is_array($decoded) || ! is_array($decoded['insights'] ?? null)) {
            throw new InvalidAiResponseException('AI response was not valid insights JSON.');
        }

        $insights = [];

        foreach ($decoded['insights'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $item['type'] ?? null;
            $text = is_string($item['text'] ?? null) ? trim($item['text']) : '';

            if (! in_array($type, self::INSIGHT_TYPES, true) || $text === '') {
                continue;
            }

            $competencyId = $item['competency_id'] ?? null;
            $competencyName = is_numeric($competencyId) ? ($namesById[(int) $competencyId] ?? null) : null;

            $insights[] = [
                'type' => $type,
                'text' => Str::limit($text, self::MAX_TEXT_CHARS),
                'competency_name' => $competencyName,
            ];

            if (count($insights) >= self::MAX_INSIGHTS) {
                break;
            }
        }

        if (empty($insights)) {
            throw new InvalidAiResponseException('AI response contained no valid insights.');
        }

        return $insights;
    }

    /**
     * Deterministic insights derived straight from BKT levels.
     */
    public function ruleBasedInsights(array $weaknesses, array $strengths): array
    {
        $insights = [];

        foreach (array_slice($weaknesses, 0, 2) as $c) {
            $percent = (int) round($c['mastery'] * 100);
            $text = $c['level'] === AdaptiveLearningService::LEVEL_LOW
                ? "Your mastery of {$c['name']} is low ({$percent}%). Review its lesson material, then retake the quiz to strengthen it."
                : "You're still developing {$c['name']} ({$percent}%). A bit more practice on this topic will help it stick.";

            $insights[] = ['type' => 'weakness', 'text' => $text, 'competency_name' => $c['name']];
        }

        if (! empty($strengths)) {
            $c = $strengths[0];
            $percent = (int) round($c['mastery'] * 100);
            $insights[] = [
                'type' => 'strength',
                'text' => "Great work on {$c['name']} ({$percent}%) — you've shown strong mastery here.",
                'competency_name' => $c['name'],
            ];
        }

        $insights[] = empty($weaknesses)
            ? ['type' => 'next_step', 'text' => 'Keep it up — try the quizzes on lessons you haven\'t taken yet to build on your progress.', 'competency_name' => null]
            : ['type' => 'next_step', 'text' => "Focus your next study session on {$weaknesses[0]['name']} before moving on to new topics.", 'competency_name' => $weaknesses[0]['name']];

        return array_slice($insights, 0, self::MAX_INSIGHTS);
    }

    /**
     * @param  array<int, int>  $competencyIds
     * @return array<int, array{competency:string, question:string}>
     */
    private function recentMistakes(User $student, array $competencyIds, array $namesById): array
    {
        if (empty($competencyIds)) {
            return [];
        }

        return QuizAnswer::query()
            ->join('quiz_attempts', 'quiz_attempts.id', '=', 'quiz_answers.quiz_attempt_id')
            ->join('quiz_questions', 'quiz_questions.id', '=', 'quiz_answers.quiz_question_id')
            ->where('quiz_attempts.student_id', $student->id)
            ->where('quiz_answers.is_correct', false)
            ->whereIn('quiz_answers.competency_id', $competencyIds)
            ->orderByDesc('quiz_answers.answered_at')
            ->limit(self::MAX_RECENT_MISTAKES)
            ->get(['quiz_answers.competency_id', 'quiz_questions.question_text'])
            ->map(fn ($row) => [
                'competency' => $namesById[$row->competency_id] ?? 'Unknown',
                'question' => Str::limit((string) $row->question_text, 200),
            ])
            ->all();
    }

    /**
     * Cache key changes whenever any mastery estimate changes (i.e. after a
     * new quiz response), so fresh insights are generated automatically.
     */
    private function cacheKey(User $student, ClassRoom $class, array $profile): string
    {
        $fingerprint = md5(json_encode(array_map(
            fn ($c) => [$c['id'], $c['observations'], round($c['mastery'], 6)],
            $profile,
        )));

        return "insights:{$student->id}:{$class->id}:{$fingerprint}";
    }

    private function publicCompetency(array $c): array
    {
        return [
            'id' => $c['id'],
            'name' => $c['name'],
            'mastery' => $c['mastery'],
            'level' => $c['level'],
        ];
    }
}
