<?php

namespace App\Services\Learning;

use App\Models\Competency;
use App\Models\QuizAnswer;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\Bkt\BayesianKnowledgeTracingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes a competency that is still referenced by quiz questions, by first
 * re-tagging those questions (and their recorded answers) to another
 * competency of the same subject, then deleting it.
 *
 * Mastery for every (student, receiving competency) touched is rebuilt by
 * replaying the student's stored answers through BKT, so student_mastery
 * stays exactly what BKT would have produced had the questions carried the
 * new tag from the start.
 */
class CompetencyMergeService
{
    public function __construct(private BayesianKnowledgeTracingService $bkt) {}

    /**
     * Which competency each affected quiz's questions would move to. With no
     * $into, a quiz's questions go to the competency most of its other
     * questions already use.
     *
     * @return Collection<int, array{quiz_id:int, lesson:?string, questions:int, answers:int, into:Competency}>
     *
     * @throws RuntimeException when a quiz has no other competency to move to
     */
    public function plan(Competency $from, ?Competency $into = null): Collection
    {
        if ($into && $into->subject_id !== $from->subject_id) {
            throw new RuntimeException("\"{$into->name}\" belongs to a different subject.");
        }

        return QuizQuestion::where('competency_id', $from->id)
            ->with('quiz.classPost:id,title')
            ->get()
            ->groupBy('quiz_id')
            ->map(function (Collection $questions, int $quizId) use ($from, $into) {
                $target = $into ?? $this->dominantCompetency($quizId, $from);

                if (! $target) {
                    throw new RuntimeException("Quiz #{$quizId} only uses \"{$from->name}\" — pass --into to choose where its questions go.");
                }

                return [
                    'quiz_id' => $quizId,
                    'lesson' => $questions->first()->quiz?->classPost?->title,
                    'questions' => $questions->count(),
                    'answers' => QuizAnswer::whereIn('quiz_question_id', $questions->pluck('id'))->count(),
                    'into' => $target,
                ];
            })
            ->values();
    }

    /**
     * Apply the plan, delete the competency and rebuild affected mastery.
     *
     * @return array{questions:int, answers:int, mastery_rebuilt:int}
     */
    public function merge(Competency $from, ?Competency $into = null): array
    {
        $plan = $this->plan($from, $into);

        return DB::transaction(function () use ($from, $plan) {
            $movedQuestions = 0;
            $movedAnswerIds = collect();

            foreach ($plan as $step) {
                $questionIds = QuizQuestion::where('quiz_id', $step['quiz_id'])->where('competency_id', $from->id)->pluck('id');
                $answerIds = QuizAnswer::whereIn('quiz_question_id', $questionIds)->pluck('id');

                $movedQuestions += QuizQuestion::whereIn('id', $questionIds)->update(['competency_id' => $step['into']->id]);
                QuizAnswer::whereIn('id', $answerIds)->update(['competency_id' => $step['into']->id]);
                $movedAnswerIds = $movedAnswerIds->merge($answerIds);
            }

            // Answers can still carry the old tag if their question was
            // re-tagged earlier; they follow their question's current tag.
            QuizAnswer::where('competency_id', $from->id)->with('question:id,competency_id')->get()
                ->each(function (QuizAnswer $answer) use ($movedAnswerIds) {
                    $answer->update(['competency_id' => $answer->question->competency_id]);
                    $movedAnswerIds->push($answer->id);
                });

            $pairs = QuizAnswer::query()
                ->join('quiz_attempts', 'quiz_attempts.id', '=', 'quiz_answers.quiz_attempt_id')
                ->whereIn('quiz_answers.id', $movedAnswerIds)
                ->distinct()
                ->get(['quiz_attempts.student_id', 'quiz_answers.competency_id']);

            // Cascades the competency's own student_mastery rows.
            $from->delete();

            foreach ($pairs as $pair) {
                $this->rebuildMastery(User::find($pair->student_id), Competency::find($pair->competency_id));
            }

            return ['questions' => $movedQuestions, 'answers' => $movedAnswerIds->count(), 'mastery_rebuilt' => $pairs->count()];
        });
    }

    private function dominantCompetency(int $quizId, Competency $from): ?Competency
    {
        $id = QuizQuestion::where('quiz_id', $quizId)
            ->where('competency_id', '!=', $from->id)
            ->selectRaw('competency_id, count(*) as uses')
            ->groupBy('competency_id')
            ->orderByDesc('uses')
            ->orderBy('competency_id')
            ->value('competency_id');

        return $id ? Competency::find($id) : null;
    }

    /** Replay the student's graded answers for this competency in order. */
    private function rebuildMastery(User $student, Competency $competency): void
    {
        $record = $this->bkt->initializeMastery($student, $competency);

        $answers = QuizAnswer::query()
            ->join('quiz_attempts', 'quiz_attempts.id', '=', 'quiz_answers.quiz_attempt_id')
            ->where('quiz_attempts.student_id', $student->id)
            ->where('quiz_answers.competency_id', $competency->id)
            ->whereNotNull('quiz_answers.is_correct')
            ->orderBy('quiz_answers.answered_at')
            ->orderBy('quiz_answers.id')
            ->get(['quiz_answers.is_correct', 'quiz_answers.answered_at']);

        $responses = $answers->pluck('is_correct')->map(fn ($c) => (bool) $c)->all();
        $trajectory = $this->bkt->replay((float) $record->p_l0, [
            'p_g' => (float) $record->p_g,
            'p_s' => (float) $record->p_s,
            'p_t' => (float) $record->p_t,
        ], $responses);

        $correct = count(array_filter($responses));
        $last = $answers->last();

        $record->update([
            'current_mastery' => $trajectory ? end($trajectory) : (float) $record->p_l0,
            'observations_count' => count($responses),
            'correct_count' => $correct,
            'incorrect_count' => count($responses) - $correct,
            'last_response' => $last ? ($last->is_correct ? 'correct' : 'incorrect') : null,
            'last_updated_at' => $last?->answered_at,
        ]);
    }
}
