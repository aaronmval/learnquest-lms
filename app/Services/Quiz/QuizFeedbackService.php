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
