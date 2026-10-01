<?php

namespace App\Services\Learning;

use App\Models\ClassRoom;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\StudentMastery;
use App\Models\Subject;
use App\Models\User;
use App\Services\Bkt\BayesianKnowledgeTracingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Professor dashboard analytics across the sections a professor manages.
 * Like StudentAnalyticsService, mastery is reconstructed by replaying each
 * student's stored quiz responses through BKT, so the charts can be
 * filtered by section/quarter/date and still come from the mastery model
 * rather than raw quiz percentages.
 */
class ProfessorAnalyticsService
{
    public function __construct(
        private BayesianKnowledgeTracingService $bkt,
        private AdaptiveLearningService $adaptive,
    ) {
    }

    /**
     * Non-archived sections the professor manages: ones they created, plus
     * every section of a subject they own or collaborate on (the same rule
     * as ClassRoom::isManagedBy).
     */
    public function managedClasses(User $professor): Collection
    {
        $subjectIds = Subject::where('owner_id', $professor->id)
            ->orWhereHas('collaborators', fn ($q) => $q->where('user_id', $professor->id))
            ->pluck('id');

        return ClassRoom::query()
            ->whereNull('archived_at')
            ->where(fn ($q) => $q->where('professor_id', $professor->id)->orWhereIn('subject_id', $subjectIds))
            ->with(['parentSubject.competencies', 'students:users.id,users.name'])
            ->orderBy('name')
            ->orderBy('section')
            ->get();
    }

    /**
     * @param  ?int  $classId  narrow to one managed section; any other id is a 404.
     * @param  ?string  $quarter  e.g. "1st Quarter" — matched against the quiz's lesson post.
     * @param  ?int  $subjectId  narrow to the managed sections of one subject; any other id is a 404.
     *
     * @throws ModelNotFoundException
     */
    public function dashboard(User $professor, ?int $classId = null, ?string $quarter = null, ?Carbon $start = null, ?Carbon $end = null, ?int $subjectId = null): array
    {
        $managed = $this->managedClasses($professor);
        $classes = $subjectId ? $managed->where('subject_id', $subjectId)->values() : $managed;

        if ($subjectId && $classes->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(Subject::class, [$subjectId]);
        }

        if ($classId) {
            $classes = $classes->where('id', $classId)->values();

            if ($classes->isEmpty()) {
                throw (new ModelNotFoundException)->setModel(ClassRoom::class, [$classId]);
            }
        }

        $competencies = $classes
            ->flatMap(fn (ClassRoom $c) => $c->parentSubject?->competencies ?? collect())
            ->unique('id')
            ->keyBy('id');
        $labels = $this->competencyLabels($competencies);

        $studentIds = $classes->flatMap(fn (ClassRoom $c) => $c->students->pluck('id'))->unique()->values()->all();
        $params = $this->bktParams($studentIds, $competencies->keys()->all());
        $responses = $this->responses($classes, $studentIds, $competencies->keys()->all(), $quarter, $start, $end);

        [$mastery, $observations] = $this->replay($responses, $params);
        $quizAverages = $this->quizAverages($classes, $studentIds, $quarter, $start, $end);

        $students = $this->studentRows($classes, $labels, $params, $mastery, $observations, $quizAverages);

        return [
            'filters' => [
                'subject_id' => $subjectId,
                'class_id' => $classId,
                'quarter' => $quarter,
                'start' => $start?->toDateString(),
                'end' => $end?->toDateString(),
            ],
            'subjects' => $managed
                ->map(fn (ClassRoom $c) => $c->parentSubject)
                ->filter()
                ->unique('id')
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->map(fn (Subject $s) => ['id' => $s->id, 'name' => $s->name])
                ->values()
                ->all(),
            'sections' => $managed
                ->map(fn (ClassRoom $c) => ['id' => $c->id, 'label' => $this->sectionLabel($c), 'subject_id' => $c->subject_id])
                ->values()
                ->all(),
            'competencies' => $this->competencyRows($classes, $labels, $params, $mastery, $observations),
            'quiz_scores' => $this->quizScores($classes, $studentIds, $quarter, $start, $end),
            'students' => $students,
            'student_count' => count($students),
            'record_count' => $responses->count(),
        ];
    }

    public function sectionLabel(ClassRoom $class): string
    {
        $subject = $class->subject ?: ($class->parentSubject?->name ?? $class->name);

        return $class->section ? "{$subject} - {$class->section}" : $subject;
    }

    /**
     * Competency names, suffixed with the subject when more than one
     * subject is in scope so same-named competencies stay distinguishable.
     *
     * @return array<int, string>
     */
    private function competencyLabels(Collection $competencies): array
    {
        $multipleSubjects = $competencies->pluck('subject_id')->unique()->count() > 1;
        $subjectNames = Subject::whereIn('id', $competencies->pluck('subject_id')->unique())->pluck('name', 'id');

        return $competencies
            ->mapWithKeys(fn ($c) => [
                $c->id => $multipleSubjects ? "{$c->name} ({$subjectNames[$c->subject_id]})" : $c->name,
            ])
            ->all();
    }

    /**
     * BKT parameters per (student, competency): the student's own record if
     * one exists, otherwise the configured defaults.
     *
     * @return array{defaults: array, records: array<string, array>}
     */
    private function bktParams(array $studentIds, array $competencyIds): array
    {
        $defaults = [
            'p_l0' => (float) config('bkt.default_pl0'),
            'p_g' => (float) config('bkt.default_pg'),
            'p_s' => (float) config('bkt.default_ps'),
            'p_t' => (float) config('bkt.default_pt'),
        ];

        $records = [];
        if ($studentIds && $competencyIds) {
            StudentMastery::whereIn('student_id', $studentIds)
                ->whereIn('competency_id', $competencyIds)
                ->get()
                ->each(function (StudentMastery $r) use (&$records) {
                    $records["{$r->student_id}:{$r->competency_id}"] = [
                        'p_l0' => (float) $r->p_l0, 'p_g' => (float) $r->p_g, 'p_s' => (float) $r->p_s, 'p_t' => (float) $r->p_t,
                    ];
                });
        }

        return ['defaults' => $defaults, 'records' => $records];
    }

    private function paramsFor(array $params, int $studentId, int $competencyId): array
    {
        return $params['records']["{$studentId}:{$competencyId}"] ?? $params['defaults'];
    }

    /**
     * Graded responses by enrolled students in the in-scope sections, in
     * chronological order — the observation sequence BKT consumed.
     */
    private function responses(Collection $classes, array $studentIds, array $competencyIds, ?string $quarter, ?Carbon $start, ?Carbon $end): Collection
    {
        if (empty($competencyIds) || empty($studentIds)) {
            return collect();
        }

        return QuizAnswer::query()
            ->join('quiz_attempts', 'quiz_attempts.id', '=', 'quiz_answers.quiz_attempt_id')
            ->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->join('class_posts', 'class_posts.id', '=', 'quizzes.class_post_id')
            ->whereIn('quiz_attempts.student_id', $studentIds)
            ->whereIn('class_posts.class_id', $classes->pluck('id'))
            ->whereIn('quiz_answers.competency_id', $competencyIds)
            ->whereNotNull('quiz_answers.is_correct')
            ->when($quarter, fn (Builder $q) => $q->where('class_posts.quarter', $quarter))
            ->when($start, fn (Builder $q) => $q->where('quiz_answers.answered_at', '>=', $start->copy()->startOfDay()))
            ->when($end, fn (Builder $q) => $q->where('quiz_answers.answered_at', '<=', $end->copy()->endOfDay()))
            ->orderBy('quiz_answers.answered_at')
            ->orderBy('quiz_answers.id')
            ->get(['quiz_attempts.student_id', 'quiz_answers.competency_id', 'quiz_answers.is_correct']);
    }

    /**
     * Replay every student's responses through BKT.
     *
     * @return array{0: array<string, float>, 1: array<string, int>}  keyed "studentId:competencyId"
     */
    private function replay(Collection $responses, array $params): array
    {
        $mastery = [];
        $observations = [];

        foreach ($responses as $response) {
            $key = "{$response->student_id}:{$response->competency_id}";
            $p = $this->paramsFor($params, $response->student_id, $response->competency_id);

            $mastery[$key] = $this->bkt->nextMastery($mastery[$key] ?? $p['p_l0'], (bool) $response->is_correct, $p['p_g'], $p['p_s'], $p['p_t']);
            $observations[$key] = ($observations[$key] ?? 0) + 1;
        }

        return [$mastery, $observations];
    }

    /**
     * One row per (section, enrolled student), strongest first; students
     * with no assessed competencies sort last.
     */
    private function studentRows(Collection $classes, array $labels, array $params, array $mastery, array $observations, array $quizAverages): array
    {
        $rows = [];

        foreach ($classes as $class) {
            $competencies = $class->parentSubject?->competencies ?? collect();

            foreach ($class->students as $student) {
                $values = [];
                $assessed = [];

                foreach ($competencies as $c) {
                    $key = "{$student->id}:{$c->id}";
                    $values[$labels[$c->id]] = $mastery[$key] ?? $this->paramsFor($params, $student->id, $c->id)['p_l0'];
                    if (($observations[$key] ?? 0) > 0) {
                        $assessed[$labels[$c->id]] = $values[$labels[$c->id]];
                    }
                }

                $overall = $values ? array_sum($values) / count($values) : null;
                arsort($assessed);

                $rows[] = [
                    'id' => "{$class->id}-{$student->id}",
                    'student_id' => $student->id,
                    'class_id' => $class->id,
                    'name' => $student->name,
                    'section' => $this->sectionLabel($class),
                    'masteryScore' => $overall === null ? null : $this->percent($overall),
                    'level' => $overall === null ? null : $this->adaptive->classify($overall),
                    'assessed' => ! empty($assessed),
                    'quizAverage' => $quizAverages["{$class->id}:{$student->id}"] ?? null,
                    'lessonMastery' => array_map(fn ($v) => $this->percent($v), $values),
                    'strength' => array_key_first($assessed),
                    'weakness' => array_key_last($assessed),
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$b['assessed'], $b['masteryScore'] ?? -1] <=> [$a['assessed'], $a['masteryScore'] ?? -1]);

        return $rows;
    }

    /**
     * Class-average BKT mastery per competency across enrolled students.
     * Unassessed students count at their P(L0) prior, matching what the
     * student dashboard shows them; assessed/low counts say how much of
     * the average comes from actual responses.
     */
    private function competencyRows(Collection $classes, array $labels, array $params, array $mastery, array $observations): array
    {
        $acc = [];

        foreach ($classes as $class) {
            foreach ($class->parentSubject?->competencies ?? [] as $c) {
                $acc[$c->id] ??= ['sum' => 0.0, 'students' => 0, 'assessed' => 0, 'low' => 0];

                foreach ($class->students as $student) {
                    $key = "{$student->id}:{$c->id}";
                    $value = $mastery[$key] ?? $this->paramsFor($params, $student->id, $c->id)['p_l0'];

                    $acc[$c->id]['sum'] += $value;
                    $acc[$c->id]['students']++;

                    if (($observations[$key] ?? 0) > 0) {
                        $acc[$c->id]['assessed']++;
                        if ($this->adaptive->classify($value) === AdaptiveLearningService::LEVEL_LOW) {
                            $acc[$c->id]['low']++;
                        }
                    }
                }
            }
        }

        return collect($acc)
            ->map(function ($a, $id) use ($labels) {
                $avg = $a['students'] ? $a['sum'] / $a['students'] : null;

                return [
                    'id' => $id,
                    'name' => $labels[$id],
                    'mastery' => $avg === null ? null : $this->percent($avg),
                    'level' => $avg === null ? null : $this->adaptive->classify($avg),
                    'students' => $a['students'],
                    'assessed_students' => $a['assessed'],
                    'low_students' => $a['low'],
                ];
            })
            ->values()
            ->all();
    }

    private function submittedAttempts(Collection $classes, array $studentIds, ?string $quarter, ?Carbon $start, ?Carbon $end): Builder
    {
        return QuizAttempt::query()
            ->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->join('class_posts', 'class_posts.id', '=', 'quizzes.class_post_id')
            ->whereIn('quiz_attempts.student_id', $studentIds ?: [0])
            ->whereIn('class_posts.class_id', $classes->pluck('id'))
            ->whereNotNull('quiz_attempts.submitted_at')
            ->where('quiz_attempts.score_total', '>', 0)
            ->when($quarter, fn (Builder $q) => $q->where('class_posts.quarter', $quarter))
            ->when($start, fn (Builder $q) => $q->where('quiz_attempts.submitted_at', '>=', $start->copy()->startOfDay()))
            ->when($end, fn (Builder $q) => $q->where('quiz_attempts.submitted_at', '<=', $end->copy()->endOfDay()));
    }

    /**
     * Raw quiz scores (percent correct) averaged per month across the
     * in-scope sections — deliberately separate from BKT mastery.
     */
    private function quizScores(Collection $classes, array $studentIds, ?string $quarter, ?Carbon $start, ?Carbon $end): array
    {
        return $this->submittedAttempts($classes, $studentIds, $quarter, $start, $end)
            ->orderBy('quiz_attempts.submitted_at')
            ->get(['quiz_attempts.score_correct', 'quiz_attempts.score_total', 'quiz_attempts.submitted_at'])
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

    /**
     * Raw average quiz % per (section, student).
     *
     * @return array<string, float>  keyed "classId:studentId"
     */
    private function quizAverages(Collection $classes, array $studentIds, ?string $quarter, ?Carbon $start, ?Carbon $end): array
    {
        return $this->submittedAttempts($classes, $studentIds, $quarter, $start, $end)
            ->get(['quiz_attempts.student_id', 'class_posts.class_id', 'quiz_attempts.score_correct', 'quiz_attempts.score_total'])
            ->groupBy(fn ($a) => "{$a->class_id}:{$a->student_id}")
            ->map(fn ($attempts) => round($attempts->avg(fn ($a) => $a->score_correct / $a->score_total * 100), 1))
            ->all();
    }

    private function percent(float $probability): float
    {
        return round($probability * 100, 1);
    }
}
