<?php

namespace App\Services\Learning;

use App\Models\ClassRoom;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\StudentMastery;
use App\Models\User;
use App\Services\Bkt\BayesianKnowledgeTracingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Student dashboard analytics. Mastery figures are reconstructed by
 * replaying the student's stored quiz responses through BKT — the same
 * steps that produced student_mastery — so the charts can be filtered by
 * quarter/date and still come from the actual mastery model, not quiz %.
 */
class StudentAnalyticsService
{
    public function __construct(
        private BayesianKnowledgeTracingService $bkt,
        private AdaptiveLearningService $adaptive,
    ) {
    }

    /**
     * @param  ?string  $quarter  e.g. "1st Quarter" — matched against the quiz's lesson post.
     */
    public function dashboard(User $student, ?string $quarter = null, ?Carbon $start = null, ?Carbon $end = null): array
    {
        $classes = $student->enrolledClasses()
            ->with('parentSubject.competencies')
            ->orderByDesc('class_enrollments.created_at')
            ->get();

        $competencies = $classes
            ->flatMap(fn (ClassRoom $c) => $c->parentSubject?->competencies ?? collect())
            ->unique('id')
            ->keyBy('id');

        $params = $this->bktParams($student, $competencies->keys()->all());
        $responses = $this->responses($student, $classes, $competencies->keys()->all(), $quarter, $start, $end);

        [$finalMastery, $observations, $trend] = $this->replay($responses, $competencies->keys()->all(), $params);

        return [
            'filters' => [
                'quarter' => $quarter,
                'start' => $start?->toDateString(),
                'end' => $end?->toDateString(),
            ],
            'subjects' => $classes->map(fn (ClassRoom $class) => $this->subjectPayload($class, $finalMastery, $observations))->values()->all(),
            'quiz_scores' => $this->quizScores($student, $classes, $quarter, $start, $end),
            'mastery_trend' => $trend,
            'record_count' => $responses->count(),
        ];
    }

    /**
     * BKT parameters per competency: the student's own record if one exists,
     * otherwise the configured defaults a new record would be seeded with.
     *
     * @return array<int, array{p_l0: float, p_g: float, p_s: float, p_t: float}>
     */
    private function bktParams(User $student, array $competencyIds): array
    {
        $defaults = [
            'p_l0' => (float) config('bkt.default_pl0'),
            'p_g' => (float) config('bkt.default_pg'),
            'p_s' => (float) config('bkt.default_ps'),
            'p_t' => (float) config('bkt.default_pt'),
        ];

        $records = StudentMastery::where('student_id', $student->id)
            ->whereIn('competency_id', $competencyIds)
            ->get()
            ->keyBy('competency_id');

        $params = [];
        foreach ($competencyIds as $id) {
            $record = $records->get($id);
            $params[$id] = $record
                ? ['p_l0' => (float) $record->p_l0, 'p_g' => (float) $record->p_g, 'p_s' => (float) $record->p_s, 'p_t' => (float) $record->p_t]
                : $defaults;
        }

        return $params;
    }

    /**
     * Graded responses in chronological order — the observation sequence BKT
     * consumed. Unanswered questions (is_correct null) never updated BKT.
     */
    private function responses(User $student, Collection $classes, array $competencyIds, ?string $quarter, ?Carbon $start, ?Carbon $end): Collection
    {
        if (empty($competencyIds)) {
            return collect();
        }

        return QuizAnswer::query()
            ->join('quiz_attempts', 'quiz_attempts.id', '=', 'quiz_answers.quiz_attempt_id')
            ->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->join('class_posts', 'class_posts.id', '=', 'quizzes.class_post_id')
            ->where('quiz_attempts.student_id', $student->id)
            ->whereIn('class_posts.class_id', $classes->pluck('id'))
            ->whereIn('quiz_answers.competency_id', $competencyIds)
            ->whereNotNull('quiz_answers.is_correct')
            ->when($quarter, fn (Builder $q) => $q->where('class_posts.quarter', $quarter))
            ->when($start, fn (Builder $q) => $q->where('quiz_answers.answered_at', '>=', $start->copy()->startOfDay()))
            ->when($end, fn (Builder $q) => $q->where('quiz_answers.answered_at', '<=', $end->copy()->endOfDay()))
            ->orderBy('quiz_answers.answered_at')
            ->orderBy('quiz_answers.id')
            ->get(['quiz_answers.competency_id', 'quiz_answers.is_correct', 'quiz_answers.answered_at']);
    }

    /**
     * Replay responses through BKT, snapshotting the average mastery across
     * every in-scope competency at the end of each month with activity.
     *
     * @return array{0: array<int, float>, 1: array<int, int>, 2: array<int, array{period: string, label: string, mastery: float}>}
     */
    private function replay(Collection $responses, array $competencyIds, array $params): array
    {
        $mastery = [];
        $observations = [];
        foreach ($competencyIds as $id) {
            $mastery[$id] = $params[$id]['p_l0'];
            $observations[$id] = 0;
        }

        $trend = [];

        foreach ($responses->groupBy(fn ($r) => $r->answered_at->format('Y-m')) as $period => $monthResponses) {
            foreach ($monthResponses as $response) {
                $id = $response->competency_id;
                $p = $params[$id];
                $mastery[$id] = $this->bkt->nextMastery($mastery[$id], (bool) $response->is_correct, $p['p_g'], $p['p_s'], $p['p_t']);
                $observations[$id]++;
            }

            $trend[] = [
                'period' => $period,
                'label' => Carbon::createFromFormat('Y-m-d', "{$period}-01")->format('M Y'),
                'mastery' => $this->percent(array_sum($mastery) / count($mastery)),
            ];
        }

        return [$mastery, $observations, $trend];
    }

    private function subjectPayload(ClassRoom $class, array $finalMastery, array $observations): array
    {
        $competencies = $class->parentSubject?->competencies ?? collect();

        $rows = $competencies->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'mastery' => $this->percent($finalMastery[$c->id]),
            'level' => $this->adaptive->classify($finalMastery[$c->id]),
            'observations' => $observations[$c->id],
        ])->values();

        return [
            'class_id' => $class->id,
            'label' => $class->subject ?: ($class->parentSubject?->name ?? $class->name),
            'class_name' => $class->name,
            'section' => $class->section,
            'mastery' => $rows->isEmpty() ? null : round($rows->avg('mastery'), 1),
            'assessed' => $rows->contains(fn ($r) => $r['observations'] > 0),
            'competencies' => $rows->all(),
        ];
    }

    /**
     * Raw quiz scores (percent correct) averaged per month — deliberately
     * separate from BKT mastery.
     *
     * @return array<int, array{period: string, label: string, average: float, attempts: int}>
     */
    private function quizScores(User $student, Collection $classes, ?string $quarter, ?Carbon $start, ?Carbon $end): array
    {
        $attempts = QuizAttempt::query()
            ->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->join('class_posts', 'class_posts.id', '=', 'quizzes.class_post_id')
            ->where('quiz_attempts.student_id', $student->id)
            ->whereIn('class_posts.class_id', $classes->pluck('id'))
            ->whereNotNull('quiz_attempts.submitted_at')
            ->where('quiz_attempts.score_total', '>', 0)
            ->when($quarter, fn (Builder $q) => $q->where('class_posts.quarter', $quarter))
            ->when($start, fn (Builder $q) => $q->where('quiz_attempts.submitted_at', '>=', $start->copy()->startOfDay()))
            ->when($end, fn (Builder $q) => $q->where('quiz_attempts.submitted_at', '<=', $end->copy()->endOfDay()))
            ->orderBy('quiz_attempts.submitted_at')
            ->get(['quiz_attempts.score_correct', 'quiz_attempts.score_total', 'quiz_attempts.submitted_at']);

        return $attempts
            ->groupBy(fn ($a) => Carbon::parse($a->submitted_at)->format('Y-m'))
            ->map(fn ($month, $period) => [
                'period' => $period,
                'label' => Carbon::createFromFormat('Y-m-d', "{$period}-01")->format('M Y'),
                'average' => round($month->avg(fn ($a) => $a->score_correct / $a->score_total * 100), 1),
                'attempts' => $month->count(),
            ])
            ->values()
            ->all();
    }

    private function percent(float $probability): float
    {
        return round($probability * 100, 1);
    }
}
