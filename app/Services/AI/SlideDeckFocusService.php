<?php

namespace App\Services\AI;

use App\Models\User;
use App\Services\Learning\AdaptiveLearningService;
use App\Services\Learning\ProfessorAnalyticsService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Targeted focus for a generated slide deck. The class's weak and strong
 * competencies come from its BKT mastery (ProfessorAnalyticsService); Llama
 * only receives them as context and tags the slides it emphasised. This
 * service decides whether a focus is needed, and afterwards reports what
 * actually made it into the deck.
 */
class SlideDeckFocusService
{
    public const STATUS_APPLIED = 'applied';

    /** Requested, but the reading doesn't cover any of the weak competencies. */
    public const STATUS_NOT_COVERED = 'not_covered';

    /** Every assessed competency is already at high class mastery. */
    public const STATUS_NOT_NEEDED = 'not_needed';

    /** No student in the class has answered a quiz yet. */
    public const STATUS_NO_DATA = 'no_data';

    private const MAX_TOPICS = 3;

    public function __construct(private ProfessorAnalyticsService $analytics)
    {
    }

    /**
     * The class's weakest and strongest assessed competencies. Unassessed
     * competencies sit at their P(L0) prior and are never counted as either.
     *
     * @return array{class: string, status: ?string, weaknesses: array<int, array{name: string, mastery: float}>, strengths: array<int, array{name: string, mastery: float}>}
     *
     * @throws ModelNotFoundException when the professor doesn't manage the class
     */
    public function plan(User $professor, int $classId): array
    {
        $dashboard = $this->analytics->dashboard($professor, $classId);

        $assessed = collect($dashboard['competencies'])->filter(fn ($c) => $c['assessed_students'] > 0);
        $topic = fn ($c) => ['name' => $c['name'], 'mastery' => $c['mastery']];

        $weaknesses = $assessed
            ->filter(fn ($c) => $c['level'] !== AdaptiveLearningService::LEVEL_HIGH)
            ->sortBy('mastery')
            ->take(self::MAX_TOPICS)
            ->map($topic)
            ->values()
            ->all();

        $strengths = $assessed
            ->filter(fn ($c) => $c['level'] === AdaptiveLearningService::LEVEL_HIGH)
            ->sortByDesc('mastery')
            ->take(self::MAX_TOPICS)
            ->map($topic)
            ->values()
            ->all();

        return [
            'class' => collect($dashboard['sections'])->firstWhere('id', $classId)['label'] ?? 'this class',
            'status' => match (true) {
                $assessed->isEmpty() => self::STATUS_NO_DATA,
                empty($weaknesses) => self::STATUS_NOT_NEEDED,
                default => null, // decided by report() once the deck exists
            },
            'weaknesses' => $weaknesses,
            'strengths' => $strengths,
        ];
    }

    /** Whether the plan has weak competencies worth sending to Llama. */
    public function isNeeded(array $plan): bool
    {
        return $plan['status'] === null;
    }

    /**
     * What the professor is told about the focus, based on the slides the
     * generated deck actually tagged.
     *
     * @param  array{title: string, slides: array<int, array<string, mixed>>}|null  $deck
     * @return array{status: string, class: string, message: string, topics: array<int, array{name: string, mastery: float, slides: int}>}
     */
    public function report(array $plan, ?array $deck = null): array
    {
        $class = $plan['class'];

        if (! $this->isNeeded($plan)) {
            return [
                'status' => $plan['status'],
                'class' => $class,
                'message' => $plan['status'] === self::STATUS_NO_DATA
                    ? "Targeted focus was not applied: {$class} has no quiz results yet, so the deck covers the reading evenly."
                    : "Targeted focus was not needed: {$class} shows high mastery in every assessed competency.",
                'topics' => [],
            ];
        }

        $counts = collect($deck['slides'] ?? [])->pluck('focus')->filter()->countBy();

        $topics = array_map(fn ($t) => $t + ['slides' => (int) ($counts[$t['name']] ?? 0)], $plan['weaknesses']);
        $covered = array_values(array_filter($topics, fn ($t) => $t['slides'] > 0));

        if (empty($covered)) {
            $names = implode(', ', array_column($topics, 'name'));

            return [
                'status' => self::STATUS_NOT_COVERED,
                'class' => $class,
                'message' => "Targeted focus was not applied: this reading doesn't cover {$class}'s weak competencies ({$names}).",
                'topics' => $topics,
            ];
        }

        $summary = implode(', ', array_map(
            fn ($t) => "{$t['name']} ({$t['slides']} ".($t['slides'] === 1 ? 'slide' : 'slides').')',
            $covered,
        ));
        $skipped = count($topics) - count($covered);

        return [
            'status' => self::STATUS_APPLIED,
            'class' => $class,
            'message' => "Targeted focus applied for {$class}: {$summary}."
                .($skipped ? ' The reading does not cover the other weak '.($skipped === 1 ? 'competency' : 'competencies').'.' : ''),
            'topics' => $topics,
        ];
    }
}
