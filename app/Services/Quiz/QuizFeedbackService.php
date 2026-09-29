<?php

namespace App\Services\Quiz;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizFeedback;
use App\Models\User;

/**
 * Collects and summarizes student feedback on a quiz, and turns that
 * summary into compact prompt context so AI regeneration can be informed
 * by it. This never touches BKT mastery — it only shapes what Llama
 * generates next.
 */
class QuizFeedbackService
{
    private const DIFFICULTY_VALUES = ['too_easy', 'just_right', 'too_hard'];

    private const MAX_COMMENTS_IN_CONTEXT = 5;

    public function submit(QuizAttempt $attempt, User $student, array $data): QuizFeedback
    {
        return QuizFeedback::create([
            'quiz_attempt_id' => $attempt->id,
            'quiz_id' => $attempt->quiz_id,
            'student_id' => $student->id,
            'rating' => $data['rating'],
            'difficulty' => $data['difficulty'],
            'comment' => $data['comment'] ?? null,
        ]);
    }

    /**
     * @return array{
     *     count: int,
     *     average_rating: ?float,
     *     difficulty: array<string, array{count: int, percent: int}>,
     *     recent_comments: array<int, array{rating: int, difficulty: string, comment: string, submitted_at: string}>
     * }
     */
    public function summaryForQuiz(Quiz $quiz): array
    {
        $feedback = $quiz->feedback()->latest()->get();
        $count = $feedback->count();

        $difficultyBreakdown = collect(self::DIFFICULTY_VALUES)->mapWithKeys(function ($key) use ($feedback, $count) {
            $n = $feedback->where('difficulty', $key)->count();

            return [$key => ['count' => $n, 'percent' => $count ? (int) round($n / $count * 100) : 0]];
        })->all();

        $recentComments = $feedback
            ->filter(fn ($f) => filled($f->comment))
            ->take(10)
            ->map(fn ($f) => [
                'rating' => $f->rating,
                'difficulty' => $f->difficulty,
                'comment' => $f->comment,
                'submitted_at' => $f->created_at->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'count' => $count,
            'average_rating' => $count ? round((float) $feedback->avg('rating'), 2) : null,
            'difficulty' => $difficultyBreakdown,
            'recent_comments' => $recentComments,
        ];
    }

    /**
     * Feedback broken down for the Quiz & AI Setup visualization: rating
     * distribution, and perceived difficulty per BKT mastery level the
     * student's quiz was adapted to (from the attempt), so a teacher can
     * see whether adaptive quizzes feel right to each group. Student names
     * are never included.
     *
     * @return array{
     *     count: int,
     *     average_rating: ?float,
     *     ratings: array<int, int>,
     *     groups: array<int, array{key: string, count: int, too_easy: int, just_right: int, too_hard: int, average_rating: ?float}>,
     *     comments: array<int, array{rating: int, difficulty: string, level: string, comment: string, submitted_at: string}>
     * }
     */
    public function breakdownForQuiz(Quiz $quiz): array
    {
        $feedback = $quiz->feedback()->with('attempt:id,mastery_level')->latest()->get();
        $levelOf = fn ($f) => $f->attempt?->mastery_level ?? 'unassessed';

        $group = function (string $key, $rows) {
            return [
                'key' => $key,
                'count' => $rows->count(),
                'too_easy' => $rows->where('difficulty', 'too_easy')->count(),
                'just_right' => $rows->where('difficulty', 'just_right')->count(),
                'too_hard' => $rows->where('difficulty', 'too_hard')->count(),
                'average_rating' => $rows->isEmpty() ? null : round((float) $rows->avg('rating'), 1),
            ];
        };

        // "all" first, then mastery levels from most to least advanced; empty
        // levels are left out.
        $groups = [$group('all', $feedback)];
        foreach (['high', 'developing', 'low', 'unassessed'] as $level) {
            $rows = $feedback->filter(fn ($f) => $levelOf($f) === $level);
            if ($rows->isNotEmpty()) {
                $groups[] = $group($level, $rows);
            }
        }

        return [
            'count' => $feedback->count(),
            'average_rating' => $feedback->isEmpty() ? null : round((float) $feedback->avg('rating'), 1),
            'ratings' => collect(range(1, 5))->mapWithKeys(fn ($r) => [$r => $feedback->where('rating', $r)->count()])->all(),
            'groups' => $groups,
            'comments' => $feedback
                ->filter(fn ($f) => filled($f->comment))
                ->take(10)
                ->map(fn ($f) => [
                    'rating' => $f->rating,
                    'difficulty' => $f->difficulty,
                    'level' => $levelOf($f),
                    'comment' => $f->comment,
                    'submitted_at' => $f->created_at->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Compact text block for prompt injection during regeneration. Null
     * when there's no feedback yet, so callers can skip injection cleanly.
     */
    public function promptContext(Quiz $quiz): ?string
    {
        $summary = $this->summaryForQuiz($quiz);

        if ($summary['count'] === 0) {
            return null;
        }

        $lines = [];
        $lines[] = "Student feedback on the previous version of this quiz ({$summary['count']} response(s), average rating {$summary['average_rating']}/5):";

        foreach ($summary['difficulty'] as $key => $stat) {
            $lines[] = '- '.str_replace('_', ' ', $key).": {$stat['percent']}% ({$stat['count']})";
        }

        $comments = collect($summary['recent_comments'])
            ->take(self::MAX_COMMENTS_IN_CONTEXT)
            ->map(fn ($c) => "  \"{$c['comment']}\"")
            ->implode("\n");

        if ($comments !== '') {
            $lines[] = "Representative comments:\n{$comments}";
        }

        return implode("\n", $lines);
    }
}
