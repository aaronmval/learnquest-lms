<?php

namespace App\Services\Bkt;

use App\Models\Competency;
use App\Models\StudentMastery;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Bayesian Knowledge Tracing — estimates a student's mastery of a single
 * competency from the sequence of their quiz responses. Pure math, no AI or
 * HTTP dependency: Llama never calculates mastery, this service does.
 */
class BayesianKnowledgeTracingService
{
    /**
     * Get-or-create the mastery record for a (student, competency) pair,
     * seeding P(L0)/P(T)/P(G)/P(S) from configured defaults.
     */
    public function initializeMastery(User $student, Competency $competency): StudentMastery
    {
        return StudentMastery::firstOrCreate(
            ['student_id' => $student->id, 'competency_id' => $competency->id],
            function () {
                $pl0 = (float) config('bkt.default_pl0');

                return [
                    'p_l0' => $pl0,
                    'p_t' => (float) config('bkt.default_pt'),
                    'p_g' => (float) config('bkt.default_pg'),
                    'p_s' => (float) config('bkt.default_ps'),
                    'initial_mastery' => $pl0,
                    'current_mastery' => $pl0,
                    'observations_count' => 0,
                    'correct_count' => 0,
                    'incorrect_count' => 0,
                ];
            },
        );
    }

    /**
     * Current mastery for a (student, competency) pair — get-or-create,
     * never resets an existing record.
     */
    public function getMastery(User $student, Competency $competency): StudentMastery
    {
        return $this->initializeMastery($student, $competency);
    }

    public function updateAfterCorrectResponse(User $student, Competency $competency): StudentMastery
    {
        return $this->applyUpdate($student, $competency, isCorrect: true);
    }

    public function updateAfterIncorrectResponse(User $student, Competency $competency): StudentMastery
    {
        return $this->applyUpdate($student, $competency, isCorrect: false);
    }

    /**
     * Standard BKT observation update (Corbett & Anderson, 1994):
     *
     * 1. Bayes' rule updates the prior P(L) into a posterior given whether
     *    the response was correct or incorrect, using the slip/guess rates.
     * 2. The learning transition then folds in the probability that the
     *    student learned the skill during this practice opportunity.
     */
    private function applyUpdate(User $student, Competency $competency, bool $isCorrect): StudentMastery
    {
        $mastery = $this->getMastery($student, $competency);

        $mastery->current_mastery = $this->nextMastery(
            (float) $mastery->current_mastery,
            $isCorrect,
            (float) $mastery->p_g,
            (float) $mastery->p_s,
            (float) $mastery->p_t,
        );
        $mastery->observations_count++;
        $isCorrect ? $mastery->correct_count++ : $mastery->incorrect_count++;
        $mastery->last_response = $isCorrect ? 'correct' : 'incorrect';
        $mastery->last_updated_at = now();
        $mastery->save();

        return $mastery;
    }

    /**
     * One BKT observation step — the pure math behind every update.
     */
    public function nextMastery(float $prior, bool $isCorrect, float $pG, float $pS, float $pT): float
    {
        if ($isCorrect) {
            $numerator = $prior * (1 - $pS);
            $denominator = $numerator + (1 - $prior) * $pG;
        } else {
            $numerator = $prior * $pS;
            $denominator = $numerator + (1 - $prior) * (1 - $pG);
        }

        $posterior = $denominator > 0.0 ? $numerator / $denominator : $prior;

        $updated = $posterior + (1 - $posterior) * $pT;

        return max(0.0, min(1.0, $updated));
    }

    /**
     * Replay an ordered response sequence from P(L0), returning mastery after
     * each response. Because stored mastery is produced by exactly these
     * steps, replaying a student's quiz_answers reconstructs their mastery
     * history at any point in time.
     *
     * @param  array{p_g: float, p_s: float, p_t: float}  $params
     * @param  iterable<bool>  $responses
     * @return array<int, float>
     */
    public function replay(float $pL0, array $params, iterable $responses): array
    {
        $mastery = $pL0;
        $trajectory = [];

        foreach ($responses as $isCorrect) {
            $mastery = $this->nextMastery($mastery, (bool) $isCorrect, $params['p_g'], $params['p_s'], $params['p_t']);
            $trajectory[] = $mastery;
        }

        return $trajectory;
    }

    /**
     * Average current BKT mastery across a set of competencies for this
     * student, for display purposes (e.g. a subject- or lesson-level
     * mastery stat). A competency with no mastery record yet is treated at
     * the configured P(L0) prior — BKT's default belief before any
     * evidence — without persisting a row just to compute the average.
     *
     * @return float|null Null when $competencies is empty (nothing to average).
     */
    public function averageMasteryForCompetencies(User $student, Collection $competencies): ?float
    {
        if ($competencies->isEmpty()) {
            return null;
        }

        $existing = StudentMastery::where('student_id', $student->id)
            ->whereIn('competency_id', $competencies->pluck('id'))
            ->pluck('current_mastery', 'competency_id');

        $defaultMastery = (float) config('bkt.default_pl0');

        $sum = $competencies->sum(
            fn ($competency) => isset($existing[$competency->id]) ? (float) $existing[$competency->id] : $defaultMastery
        );

        return $sum / $competencies->count();
    }
}
