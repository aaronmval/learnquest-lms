<?php

namespace App\Services\Learning;

use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\Quiz\QuizSettingsService;
use Illuminate\Support\Collection;

/**
 * Adaptive quiz difficulty driven by BKT mastery. A lesson's quiz is a
 * question bank; each student is served the teacher's question count with
 * a difficulty mix matched to their mastery of the lesson's competencies:
 *
 *   low mastery        → mix shifted toward "easy"
 *   developing mastery → the teacher's mix
 *   high mastery       → mix shifted toward "hard"
 *
 * BKT (via AdaptiveLearningService::classify) decides the level; this class
 * only applies rules to it — no AI involved.
 */
class AdaptiveQuizService
{
    private const DIFFICULTIES = ['easy', 'medium', 'hard'];

    public function __construct(
        private AdaptiveLearningService $adaptive,
        private QuizSettingsService $settings,
    ) {
    }

    /**
     * The student's BKT mastery over the competencies these questions test,
     * averaged over competencies they've actually been assessed on. Null
     * when they have no quiz evidence yet — a new student gets the
     * teacher's mix rather than being treated as "low" because of the prior.
     *
     * @param  Collection<int, QuizQuestion>  $questions  with `competency` loaded
     * @return ?array{mastery: float, level: string}
     */
    public function masteryFor(User $student, Collection $questions): ?array
    {
        $competencies = $questions->pluck('competency')->filter()->unique('id')->values();

        if ($competencies->isEmpty()) {
            return null;
        }

        $assessed = collect($this->adaptive->competencyProfile($student, $competencies))->where('assessed', true);

        if ($assessed->isEmpty()) {
            return null;
        }

        $mastery = (float) $assessed->avg('mastery');

        return ['mastery' => $mastery, 'level' => $this->adaptive->classify($mastery)];
    }

    /**
     * The teacher's mix adjusted for a mastery level (percents, sum kept).
     *
     * @param  array{easy:int, medium:int, hard:int}  $mix
     * @return array{easy:int, medium:int, hard:int}
     */
    public function mixFor(array $mix, ?string $level): array
    {
        $mix = ['easy' => (int) $mix['easy'], 'medium' => (int) $mix['medium'], 'hard' => (int) $mix['hard']];
        $shift = max(0, (int) config('quiz.adaptive_shift', 20));

        [$target, $sources] = match ($level) {
            AdaptiveLearningService::LEVEL_HIGH => ['hard', ['easy', 'medium']],
            AdaptiveLearningService::LEVEL_LOW => ['easy', ['hard', 'medium']],
            default => [null, []],
        };

        foreach ($sources as $source) {
            $moved = min($shift, $mix[$source]);
            $mix[$source] -= $moved;
            $mix[$target] += $moved;
            $shift -= $moved;
        }

        return $mix;
    }

    /**
     * How many questions of each difficulty the lesson's bank needs so every
     * mastery level can be served its full mix. Without adaptivity this is
     * just the teacher's allocation.
     *
     * @param  array  $settings  QuizSettingsService::for() output
     * @return array{easy:int, medium:int, hard:int}
     */
    public function poolAllocation(array $settings): array
    {
        $count = $settings['question_count'];
        $mix = $settings['difficulty_mix'];

        if (! ($settings['adaptive'] ?? false)) {
            return $this->settings->allocate($count, $mix);
        }

        $pool = ['easy' => 0, 'medium' => 0, 'hard' => 0];

        foreach ([AdaptiveLearningService::LEVEL_LOW, AdaptiveLearningService::LEVEL_DEVELOPING, AdaptiveLearningService::LEVEL_HIGH] as $level) {
            foreach ($this->settings->allocate($count, $this->mixFor($mix, $level)) as $difficulty => $n) {
                $pool[$difficulty] = max($pool[$difficulty], $n);
            }
        }

        return $pool;
    }

    /**
     * Pick up to $count questions matching $mix. When the bank is short of a
     * difficulty (e.g. rejected questions not replaced yet), the gap is
     * filled from the nearest difficulty in the direction the mix leans.
     *
     * @param  Collection<int, QuizQuestion>  $questions
     * @param  array{easy:int, medium:int, hard:int}  $mix
     * @return Collection<int, QuizQuestion>
     */
    public function select(Collection $questions, int $count, array $mix): Collection
    {
        $count = min($count, $questions->count());
        $wanted = $this->settings->allocate($count, $mix);
        $available = $questions->shuffle()->groupBy('difficulty')->map->values();

        $picked = collect();
        $missing = [];

        foreach (self::DIFFICULTIES as $difficulty) {
            $take = ($available->get($difficulty) ?? collect())->take($wanted[$difficulty]);
            $picked = $picked->merge($take);
            $missing[$difficulty] = $wanted[$difficulty] - $take->count();
        }

        $leansHard = $mix['hard'] >= $mix['easy'];

        foreach ($missing as $difficulty => $gap) {
            foreach ($this->fallbackOrder($difficulty, $leansHard) as $other) {
                if ($gap <= 0) {
                    break;
                }

                $spare = ($available->get($other) ?? collect())
                    ->reject(fn ($q) => $picked->contains('id', $q->id))
                    ->take($gap);
                $picked = $picked->merge($spare);
                $gap -= $spare->count();
            }
        }

        return $picked->values();
    }

    /** @return array<int, string> */
    private function fallbackOrder(string $difficulty, bool $leansHard): array
    {
        return match ($difficulty) {
            'easy' => ['medium', 'hard'],
            'hard' => ['medium', 'easy'],
            default => $leansHard ? ['hard', 'easy'] : ['easy', 'hard'],
        };
    }
}
