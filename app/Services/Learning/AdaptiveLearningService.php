<?php

namespace App\Services\Learning;

use App\Models\StudentMastery;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns BKT mastery estimates into adaptive-learning decisions: mastery
 * levels, weaknesses and strengths. Pure rules over BKT output — no AI.
 * Thresholds live in config/bkt.php so they aren't duplicated elsewhere.
 */
class AdaptiveLearningService
{
    public const LEVEL_LOW = 'low';

    public const LEVEL_DEVELOPING = 'developing';

    public const LEVEL_HIGH = 'high';

    public function classify(float $mastery): string
    {
        if ($mastery < (float) config('bkt.mastery_threshold_low')) {
            return self::LEVEL_LOW;
        }

        if ($mastery >= (float) config('bkt.mastery_threshold_high')) {
            return self::LEVEL_HIGH;
        }

        return self::LEVEL_DEVELOPING;
    }

    /**
     * Per-competency mastery profile for a student. A competency with no
     * observations yet is "unassessed" and sits at the P(L0) prior, so it
     * is never reported as a weakness or strength.
     *
     * @return array<int, array{id:int, name:string, mastery:float, level:string, observations:int, correct:int, incorrect:int, assessed:bool}>
     */
    public function competencyProfile(User $student, Collection $competencies): array
    {
        $records = StudentMastery::where('student_id', $student->id)
            ->whereIn('competency_id', $competencies->pluck('id'))
            ->get()
            ->keyBy('competency_id');

        $defaultMastery = (float) config('bkt.default_pl0');

        return $competencies->map(function ($competency) use ($records, $defaultMastery) {
            $record = $records->get($competency->id);
            $observations = (int) ($record->observations_count ?? 0);
            $mastery = $record ? (float) $record->current_mastery : $defaultMastery;

            return [
                'id' => $competency->id,
                'name' => $competency->name,
                'mastery' => $mastery,
                'level' => $this->classify($mastery),
                'observations' => $observations,
                'correct' => (int) ($record->correct_count ?? 0),
                'incorrect' => (int) ($record->incorrect_count ?? 0),
                'assessed' => $observations > 0,
            ];
        })->values()->all();
    }

    /**
     * Assessed competencies below the high-mastery threshold, weakest first.
     */
    public function weaknesses(array $profile): array
    {
        return collect($profile)
            ->filter(fn ($c) => $c['assessed'] && $c['level'] !== self::LEVEL_HIGH)
            ->sortBy('mastery')
            ->values()
            ->all();
    }

    /**
     * Assessed competencies at or above the high-mastery threshold, strongest first.
     */
    public function strengths(array $profile): array
    {
        return collect($profile)
            ->filter(fn ($c) => $c['assessed'] && $c['level'] === self::LEVEL_HIGH)
            ->sortByDesc('mastery')
            ->values()
            ->all();
    }
}
