<?php

namespace App\Services\AI;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Exceptions\AI\LlamaApiException;
use App\Models\User;
use App\Services\Learning\AdaptiveLearningService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "AI Strengths vs Weaknesses Analysis" for the professor dashboard.
 * ProfessorAnalyticsService supplies class-level BKT mastery, and Llama only
 * phrases it as teaching advice. If Llama is unavailable or returns invalid
 * output, rule-based notes built from the same data are returned instead.
 */
class ClassInsightService
{
    private const INSIGHT_TYPES = ['strength', 'weakness', 'intervention'];

    private const MAX_INSIGHTS = 4;

    private const MAX_TEXT_CHARS = 300;

    private const CACHE_TTL_SECONDS = 86400;

    public function __construct(
        private LlamaService $llama,
        private PromptService $prompts,
    ) {
    }

    /**
     * @param  array  $dashboard  output of ProfessorAnalyticsService::dashboard()
     * @return array{insights: array<int, array{type:string, text:string, competency_name:?string}>, source: string, generated_at: ?string}
     */
    public function insightsFor(User $professor, array $dashboard, bool $refresh = false): array
    {
        $competencies = array_values(array_filter($dashboard['competencies'], fn ($c) => $c['assessed_students'] > 0));

        if (empty($competencies)) {
            return ['insights' => [], 'source' => 'empty', 'generated_at' => null];
        }

        $cacheKey = $this->cacheKey($professor, $dashboard);

        if (! $refresh && ($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $summary = $this->summary($dashboard);
        $namesById = array_column($dashboard['competencies'], 'name', 'id');
        $source = 'ai';

        try {
            $insights = $this->generateAiInsights($professor, $this->scopeLabel($dashboard), $dashboard['competencies'], $summary, $namesById);
        } catch (LlamaApiException|InvalidAiResponseException $e) {
            Log::channel('ai')->warning('[class-insights] AI analysis unavailable — using rule-based notes.', [
                'professor_id' => $professor->id,
                'filters' => $dashboard['filters'],
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            $source = 'rules';
            $insights = $this->ruleBasedInsights($competencies, $summary);
        }

        $result = [
            'insights' => $insights,
            'source' => $source,
            'generated_at' => now()->toIso8601String(),
        ];

        // Only cache AI output, so a transient outage doesn't pin the fallback.
        if ($source === 'ai') {
            Cache::put($cacheKey, $result, self::CACHE_TTL_SECONDS);
        }

        return $result;
    }

    /**
     * @throws LlamaApiException
     * @throws InvalidAiResponseException
     */
    private function generateAiInsights(User $professor, string $scope, array $competencies, array $summary, array $namesById): array
    {
        // Short per-call limits: if Llama is slow, LlamaService moves on to
        // the fallback model (DeepSeek) quickly rather than making the
        // professor wait out the default timeout and retries.
        $limits = array_map('intval', (array) config('services.routeway.class_insights', []));

        $result = $this->llama->chat(
            $this->prompts->classInsightMessages($scope, $competencies, $summary),
            $limits + ['max_tokens' => 600],
        );

        try {
            return $this->parseAndValidate($result['content'], $namesById);
        } catch (InvalidAiResponseException $e) {
            Log::channel('ai')->error('[class-insights] AI response failed validation.', [
                'professor_id' => $professor->id,
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
            throw new InvalidAiResponseException('AI response was not valid class insights JSON.');
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

            $insights[] = [
                'type' => $type,
                'text' => Str::limit($text, self::MAX_TEXT_CHARS),
                'competency_name' => is_numeric($competencyId) ? ($namesById[(int) $competencyId] ?? null) : null,
            ];

            if (count($insights) >= self::MAX_INSIGHTS) {
                break;
            }
        }

        if (empty($insights)) {
            throw new InvalidAiResponseException('AI response contained no valid class insights.');
        }

        return $insights;
    }

    /**
     * Deterministic notes derived straight from the class BKT levels.
     *
     * @param  array  $competencies  assessed competency rows only
     */
    public function ruleBasedInsights(array $competencies, array $summary): array
    {
        $sorted = collect($competencies)->sortByDesc('mastery')->values();
        $strengths = $sorted->where('level', AdaptiveLearningService::LEVEL_HIGH)->take(2);
        $weaknesses = $sorted->reverse()->where('level', '!=', AdaptiveLearningService::LEVEL_HIGH)->take(2);

        $insights = [];

        foreach ($strengths as $c) {
            $insights[] = [
                'type' => 'strength',
                'text' => "The class shows strong mastery of {$c['name']} (".round($c['mastery']).'%).',
                'competency_name' => $c['name'],
            ];
        }

        foreach ($weaknesses as $c) {
            $action = $c['level'] === AdaptiveLearningService::LEVEL_LOW
                ? 'consider re-teaching its key concepts with worked examples'
                : 'additional targeted practice should help consolidate it';

            $insights[] = [
                'type' => 'weakness',
                'text' => "{$c['name']} is at ".round($c['mastery'])."% class mastery — {$action}.",
                'competency_name' => $c['name'],
            ];
        }

        if ($summary['low'] > 0) {
            $noun = $summary['low'] === 1 ? 'student is' : 'students are';
            $insights[] = [
                'type' => 'intervention',
                'text' => "{$summary['low']} {$noun} at low overall mastery and may need small-group remediation.",
                'competency_name' => null,
            ];
        }

        return array_slice($insights, 0, self::MAX_INSIGHTS);
    }

    /**
     * @return array{students:int, assessed:int, low:int, quiz_average:?float}
     */
    public function summary(array $dashboard): array
    {
        $students = collect($dashboard['students']);
        $assessed = $students->where('assessed', true);
        $quizAverages = $students->pluck('quizAverage')->filter(fn ($v) => $v !== null);

        return [
            'students' => $students->count(),
            'assessed' => $assessed->count(),
            'low' => $assessed->where('level', AdaptiveLearningService::LEVEL_LOW)->count(),
            'quiz_average' => $quizAverages->isEmpty() ? null : round($quizAverages->avg(), 1),
        ];
    }

    private function scopeLabel(array $dashboard): string
    {
        $classId = $dashboard['filters']['class_id'];
        $section = $classId ? collect($dashboard['sections'])->firstWhere('id', $classId)['label'] ?? null : null;

        $subjectId = $dashboard['filters']['subject_id'] ?? null;
        $subject = $subjectId ? collect($dashboard['subjects'] ?? [])->firstWhere('id', $subjectId)['name'] ?? null : null;

        return collect([
            $subject ? "Subject: {$subject}" : null,
            $section ?? 'All sections',
            $dashboard['filters']['quarter'],
            $dashboard['filters']['start'] ? 'from '.$dashboard['filters']['start'] : null,
            $dashboard['filters']['end'] ? 'until '.$dashboard['filters']['end'] : null,
        ])->filter()->implode(', ');
    }

    /**
     * Changes whenever the filters or any class figure changes (i.e. after
     * new quiz responses), so fresh analysis is generated automatically.
     */
    private function cacheKey(User $professor, array $dashboard): string
    {
        $fingerprint = md5(json_encode([
            $dashboard['filters'],
            $dashboard['record_count'],
            array_map(fn ($c) => [$c['id'], $c['mastery'], $c['assessed_students'], $c['low_students']], $dashboard['competencies']),
        ]));

        return "class-insights:{$professor->id}:{$fingerprint}";
    }
}
