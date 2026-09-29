<?php

namespace App\Services\Quiz;

use App\Models\QuizAnswer;
use App\Models\QuizQuestion;
use App\Models\QuizQuestionReview;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Review-based "training" for AI quiz generation. Llama is not fine-tuned;
 * instead, teacher reviews (approve / reject with reason / difficulty
 * correction) and real student results are turned into guidance that is
 * added to future generation prompts for the same subject (in-context
 * learning), and into accuracy metrics for the Quiz & AI Setup page.
 *
 * Difficulty targets are configurable working values (config/quiz.php),
 * not research-fixed thresholds. Only aggregate student results are used —
 * never student names.
 */
class QuizTrainingService
{
    private const MAX_EXAMPLES = 4;

    private const MAX_COMMENTS = 3;

    private const MAX_FLAGGED = 8;

    public const REASON_LABELS = [
        'incorrect_answer' => 'incorrect answer key',
        'off_topic' => 'not based on the lesson',
        'unclear' => 'unclear or ambiguous wording',
        'too_easy' => 'too easy',
        'too_hard' => 'too hard',
        'duplicate' => 'duplicate of another question',
        'other' => 'other',
    ];

    private const REASON_GUIDANCE = [
        'incorrect_answer' => 'Double-check that "correct_answer" is truly correct according to the lesson content.',
        'off_topic' => 'Only ask about ideas that appear in the lesson content.',
        'unclear' => 'Use clear, specific wording with exactly one defensible answer.',
        'too_easy' => 'Add more reasoning or application; avoid pure recall where the label is not "easy".',
        'too_hard' => 'Keep questions within what the lesson teaches; avoid unnecessary multi-step traps.',
        'duplicate' => 'Make every question test a different idea.',
    ];

    /**
     * Guidance for the quiz-generation prompt, or null when this subject
     * has no review or response data yet.
     */
    public function promptContext(Subject $subject): ?string
    {
        $reviews = $this->reviewsFor($subject)->with('question.competency')->latest('updated_at')->get();
        $sections = array_filter([
            $this->examplesSection($reviews),
            $this->rejectionsSection($reviews),
            $this->relabelSection($reviews),
            $this->calibrationSection($subject),
        ]);

        if (empty($sections)) {
            return null;
        }

        return "TEACHER REVIEW DATA for this subject — use it to improve question quality and difficulty labels:\n\n"
            .implode("\n\n", $sections);
    }

    /**
     * Accuracy metrics for the Quiz & AI Setup page's AI training card.
     */
    public function metrics(Subject $subject): array
    {
        $reviews = $this->reviewsFor($subject)->with('question.quiz.classPost')->get();
        $reviewed = $reviews->count();
        $approved = $reviews->where('verdict', QuizQuestionReview::APPROVED)->count();
        $rejected = $reviews->where('verdict', QuizQuestionReview::REJECTED);

        $agreeing = $reviews->filter(fn ($r) => $r->teacher_difficulty === null || $r->teacher_difficulty === $r->ai_difficulty)->count();

        return [
            'reviewed' => $reviewed,
            'approved' => $approved,
            'rejected' => $rejected->count(),
            'approval_rate' => $reviewed ? (int) round($approved / $reviewed * 100) : null,
            'difficulty_agreement' => $reviewed ? (int) round($agreeing / $reviewed * 100) : null,
            'rejection_reasons' => $rejected
                ->countBy(fn ($r) => $r->reason ?: 'other')
                ->sortDesc()
                ->map(fn ($count, $reason) => ['reason' => $reason, 'label' => self::REASON_LABELS[$reason] ?? $reason, 'count' => $count])
                ->values()
                ->all(),
            'relabels' => $this->relabels($reviews),
            'by_difficulty' => $this->accuracyByDifficulty($subject),
            'flagged' => $this->flaggedQuestions($subject),
            'targets' => $this->targets(),
            'min_responses' => $this->minResponses(),
        ];
    }

    /**
     * Student results per question: responses, % correct and — once there
     * are enough responses — the difficulty the results suggest.
     *
     * @param  Collection<int, QuizQuestion>  $questions
     * @return array<int, array{responses:int, percent_correct:?int, suggested_difficulty:?string, flagged:bool}>
     */
    public function questionStats(Collection $questions): array
    {
        if ($questions->isEmpty()) {
            return [];
        }

        $rows = QuizAnswer::query()
            ->whereIn('quiz_question_id', $questions->pluck('id'))
            ->whereNotNull('is_correct')
            ->groupBy('quiz_question_id')
            ->selectRaw('quiz_question_id, count(*) as responses, sum(case when is_correct = 1 then 1 else 0 end) as correct')
            ->get()
            ->keyBy('quiz_question_id');

        return $questions->mapWithKeys(function (QuizQuestion $question) use ($rows) {
            $row = $rows->get($question->id);
            $responses = (int) ($row->responses ?? 0);
            $rate = $responses ? (int) $row->correct / $responses : null;

            return [$question->id => $this->statFor($question->difficulty, $responses, $rate)];
        })->all();
    }

    /**
     * The difficulty whose target % correct is closest to the observed rate.
     */
    public function suggestedDifficulty(float $rate): string
    {
        $targets = $this->targets();
        uasort($targets, fn ($a, $b) => abs($a - $rate) <=> abs($b - $rate));

        return array_key_first($targets);
    }

    /**
     * Flagged = enough responses, the results point to a different label,
     * and they are beyond the tolerance from the current label's target
     * (so a borderline question doesn't flip-flop).
     */
    private function statFor(string $difficulty, int $responses, ?float $rate): array
    {
        $trusted = $rate !== null && $responses >= $this->minResponses();
        $target = $this->targets()[$difficulty] ?? null;
        $suggested = $trusted ? $this->suggestedDifficulty($rate) : null;

        return [
            'responses' => $responses,
            'percent_correct' => $rate === null ? null : (int) round($rate * 100),
            'suggested_difficulty' => $suggested,
            'flagged' => $suggested !== null && $suggested !== $difficulty
                && $target !== null && abs($rate - $target) > $this->tolerance(),
        ];
    }

    private function examplesSection(Collection $reviews): ?string
    {
        $approved = $reviews->where('verdict', QuizQuestionReview::APPROVED)->filter(fn ($r) => $r->question);

        if ($approved->isEmpty()) {
            return null;
        }

        // One example per difficulty first, then the most recent others.
        $picked = $approved->unique(fn ($r) => $r->question->difficulty)
            ->merge($approved)
            ->unique('id')
            ->take(self::MAX_EXAMPLES);

        $examples = $picked->map(function (QuizQuestionReview $review) {
            $question = $review->question;
            $choices = collect($question->choices)->values()
                ->map(fn ($choice, $i) => chr(65 + $i).") {$choice}")
                ->implode(' | ');

            return "- Approved {$question->difficulty} question: {$question->question_text}\n"
                ."  Choices: {$choices}\n"
                ."  Correct: {$question->correct_answer}";
        })->implode("\n");

        return "Questions teachers APPROVED (match this quality and style; do not copy them):\n{$examples}";
    }

    private function rejectionsSection(Collection $reviews): ?string
    {
        $rejected = $reviews->where('verdict', QuizQuestionReview::REJECTED);

        if ($rejected->isEmpty()) {
            return null;
        }

        $counts = $rejected->countBy(fn ($r) => $r->reason ?: 'other')->sortDesc();

        $lines = ["Teachers REJECTED {$rejected->count()} AI question(s). Reasons: "
            .$counts->map(fn ($n, $reason) => (self::REASON_LABELS[$reason] ?? $reason)." ({$n})")->implode(', ').'.'];

        foreach ($counts->keys() as $reason) {
            if (isset(self::REASON_GUIDANCE[$reason])) {
                $lines[] = '- '.self::REASON_GUIDANCE[$reason];
            }
        }

        $comments = $rejected->filter(fn ($r) => filled($r->comment) && $r->question)->take(self::MAX_COMMENTS);
        foreach ($comments as $review) {
            $lines[] = '- Teacher note on "'.Str::limit($review->question->question_text, 80).'": '.Str::limit(trim($review->comment), 200);
        }

        return implode("\n", $lines);
    }

    private function relabelSection(Collection $reviews): ?string
    {
        $relabels = $this->relabels($reviews);

        if (empty($relabels)) {
            return null;
        }

        $lines = array_map(
            fn ($r) => "- Questions you labeled \"{$r['from']}\" were relabeled \"{$r['to']}\" by teachers {$r['count']} time(s).",
            $relabels,
        );

        return "Teacher DIFFICULTY CORRECTIONS (calibrate your labels accordingly):\n".implode("\n", $lines);
    }

    private function calibrationSection(Subject $subject): ?string
    {
        $lines = [];

        foreach ($this->accuracyByDifficulty($subject) as $row) {
            if ($row['responses'] < $this->minResponses() || $row['percent_correct'] === null) {
                continue;
            }

            $actual = $row['percent_correct'] / 100;
            $target = $row['target'];
            $observed = "students answered \"{$row['difficulty']}\" questions correctly {$row['percent_correct']}% of the time (target about ".(int) round($target * 100).'%)';

            if ($actual < $target - $this->tolerance()) {
                $lines[] = "- {$observed}: they were harder than labeled — make \"{$row['difficulty']}\" questions more straightforward.";
            } elseif ($actual > $target + $this->tolerance()) {
                $lines[] = "- {$observed}: they were easier than labeled — make \"{$row['difficulty']}\" questions more demanding.";
            }
        }

        return empty($lines) ? null : "STUDENT RESULTS by difficulty label:\n".implode("\n", $lines);
    }

    /**
     * @return array<int, array{from:string, to:string, count:int}>
     */
    private function relabels(Collection $reviews): array
    {
        return $reviews
            ->filter(fn ($r) => $r->teacher_difficulty !== null && $r->teacher_difficulty !== $r->ai_difficulty)
            ->countBy(fn ($r) => "{$r->ai_difficulty}>{$r->teacher_difficulty}")
            ->sortDesc()
            ->map(function ($count, $key) {
                [$from, $to] = explode('>', $key);

                return ['from' => $from, 'to' => $to, 'count' => $count];
            })
            ->values()
            ->all();
    }

    /**
     * Student accuracy per (final) difficulty label across the subject's
     * non-rejected questions.
     *
     * @return array<int, array{difficulty:string, target:float, responses:int, percent_correct:?int}>
     */
    private function accuracyByDifficulty(Subject $subject): array
    {
        $rows = $this->answersFor($subject)
            ->groupBy('quiz_questions.difficulty')
            ->selectRaw('quiz_questions.difficulty as difficulty, count(*) as responses, sum(case when quiz_answers.is_correct = 1 then 1 else 0 end) as correct')
            ->get()
            ->keyBy('difficulty');

        return collect($this->targets())->map(function ($target, $difficulty) use ($rows) {
            $responses = (int) ($rows->get($difficulty)->responses ?? 0);

            return [
                'difficulty' => $difficulty,
                'target' => $target,
                'responses' => $responses,
                'percent_correct' => $responses ? (int) round((int) $rows->get($difficulty)->correct / $responses * 100) : null,
            ];
        })->values()->all();
    }

    /**
     * Questions whose student results are far from their label's target.
     */
    private function flaggedQuestions(Subject $subject): array
    {
        $rows = $this->answersFor($subject)
            ->groupBy('quiz_answers.quiz_question_id')
            ->havingRaw('count(*) >= ?', [$this->minResponses()])
            ->selectRaw('quiz_answers.quiz_question_id as question_id, count(*) as responses, sum(case when quiz_answers.is_correct = 1 then 1 else 0 end) as correct')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $questions = QuizQuestion::with('quiz.classPost')->whereIn('id', $rows->pluck('question_id'))->get()->keyBy('id');

        return $rows
            ->map(function ($row) use ($questions) {
                $question = $questions->get($row->question_id);
                $rate = (int) $row->correct / (int) $row->responses;
                $stat = $question ? $this->statFor($question->difficulty, (int) $row->responses, $rate) : null;

                return $stat && $stat['flagged'] ? [
                    'question_id' => $question->id,
                    'class_post_id' => $question->quiz?->class_post_id,
                    'lesson' => $question->quiz?->classPost?->title,
                    'question' => Str::limit($question->question_text, 120),
                    'difficulty' => $question->difficulty,
                ] + $stat : null;
            })
            ->filter()
            ->sortByDesc('responses')
            ->take(self::MAX_FLAGGED)
            ->values()
            ->all();
    }

    private function reviewsFor(Subject $subject): Builder
    {
        return QuizQuestionReview::query()
            ->whereHas('question.quiz.classPost.classRoom', fn (Builder $q) => $q->where('subject_id', $subject->id));
    }

    /**
     * Graded answers to the subject's non-rejected questions.
     */
    private function answersFor(Subject $subject): Builder
    {
        return QuizAnswer::query()
            ->join('quiz_questions', 'quiz_questions.id', '=', 'quiz_answers.quiz_question_id')
            ->join('quizzes', 'quizzes.id', '=', 'quiz_questions.quiz_id')
            ->join('class_posts', 'class_posts.id', '=', 'quizzes.class_post_id')
            ->join('classes', 'classes.id', '=', 'class_posts.class_id')
            ->where('classes.subject_id', $subject->id)
            ->whereNotNull('quiz_answers.is_correct')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('quiz_question_reviews')
                ->whereColumn('quiz_question_reviews.quiz_question_id', 'quiz_questions.id')
                ->where('quiz_question_reviews.verdict', QuizQuestionReview::REJECTED));
    }

    /** @return array<string, float> */
    private function targets(): array
    {
        return array_map('floatval', (array) config('quiz.difficulty_targets'));
    }

    private function tolerance(): float
    {
        return (float) config('quiz.calibration_tolerance', 0.15);
    }

    private function minResponses(): int
    {
        return (int) config('quiz.min_responses_for_calibration', 5);
    }
}
