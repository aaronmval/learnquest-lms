<?php

namespace App\Services\Quiz;

use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Bkt\BayesianKnowledgeTracingService;
use Illuminate\Support\Facades\DB;

/**
 * Grades a submitted quiz attempt and drives the resulting BKT mastery
 * updates. This is the join point between AI-generated quiz content and
 * mastery tracking — BayesianKnowledgeTracingService itself stays pure math
 * with no grading logic.
 */
class QuizAttemptService
{
    public function __construct(private BayesianKnowledgeTracingService $bkt) {}

    /**
     * @param  array<int, array{question_id:int, selected_index:int|null}>  $submittedAnswers
     * @return array{attempt_id:int, score:array, review:array, mastery_deltas:array, lesson_mastery:array{before:?float, after:?float}, subject_mastery:array{before:?float, after:?float}}
     */
    public function submit(Quiz $quiz, User $student, array $submittedAnswers): array
    {
        return DB::transaction(function () use ($quiz, $student, $submittedAnswers) {
            $byQuestionId = collect($submittedAnswers)->keyBy('question_id');
            $questions = $quiz->questions()->with('competency')->orderBy('order_index')->get();

            // Lesson mastery = average mastery across just this lesson's
            // tested competencies. Subject mastery = average across every
            // competency defined for the subject, so it reflects standing
            // across all lessons, not only the one just attempted.
            $lessonCompetencies = $questions->pluck('competency')->unique('id')->values();
            $subject = $quiz->classPost?->classRoom?->parentSubject;
            $subjectCompetencies = $subject ? $subject->competencies()->get() : collect();

            $lessonMasteryBefore = $this->bkt->averageMasteryForCompetencies($student, $lessonCompetencies);
            $subjectMasteryBefore = $this->bkt->averageMasteryForCompetencies($student, $subjectCompetencies);

            $attempt = QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'student_id' => $student->id,
                'started_at' => now(),
                'submitted_at' => now(),
                'status' => 'submitted',
            ]);

            $correct = 0;
            $incorrect = 0;
            $skipped = 0;
            $review = [];
            $before = [];
            $firstAssessment = [];
            $touchedCompetencies = [];

            foreach ($questions as $question) {
                $submitted = $byQuestionId->get($question->id);
                $selectedIndex = $submitted['selected_index'] ?? null;

                if ($selectedIndex !== null && ! array_key_exists($selectedIndex, $question->choices)) {
                    // Out-of-range index — treat as skipped rather than erroring.
                    $selectedIndex = null;
                }

                $isCorrect = null;

                if ($selectedIndex !== null) {
                    $isCorrect = trim($question->choices[$selectedIndex]) === trim($question->correct_answer);
                    $competency = $question->competency;

                    if (! array_key_exists($competency->id, $before)) {
                        $record = $this->bkt->getMastery($student, $competency);
                        $before[$competency->id] = (float) $record->current_mastery;
                        $firstAssessment[$competency->id] = (int) $record->observations_count === 0;
                    }

                    $isCorrect
                        ? $this->bkt->updateAfterCorrectResponse($student, $competency)
                        : $this->bkt->updateAfterIncorrectResponse($student, $competency);

                    $touchedCompetencies[$competency->id] = $competency;
                    $isCorrect ? $correct++ : $incorrect++;
                } else {
                    $skipped++;
                }

                QuizAnswer::create([
                    'quiz_attempt_id' => $attempt->id,
                    'quiz_question_id' => $question->id,
                    'competency_id' => $question->competency_id,
                    'selected_index' => $selectedIndex,
                    'is_correct' => $isCorrect,
                    'answered_at' => now(),
                ]);

                $review[] = [
                    'question_id' => $question->id,
                    'selected_index' => $selectedIndex,
                    'correct_index' => array_search($question->correct_answer, $question->choices, true),
                    'is_correct' => $isCorrect,
                    'explanation' => $question->explanation,
                    'competency_name' => $question->competency->name,
                ];
            }

            $total = $questions->count();
            $attempt->update(['score_correct' => $correct, 'score_total' => $total]);

            $masteryDeltas = collect($touchedCompetencies)
                ->map(function ($competency) use ($student, $before, $firstAssessment) {
                    $after = (float) $this->bkt->getMastery($student, $competency)->current_mastery;

                    return [
                        'competency_id' => $competency->id,
                        'competency_name' => $competency->name,
                        'before' => $before[$competency->id],
                        'after' => $after,
                        // True when this attempt produced the competency's first observations.
                        'first_assessment' => $firstAssessment[$competency->id],
                    ];
                })
                ->values()
                ->all();

            return [
                'attempt_id' => $attempt->id,
                'score' => [
                    'correct' => $correct,
                    'incorrect' => $incorrect,
                    'skipped' => $skipped,
                    'total' => $total,
                    'percent' => $total ? (int) round($correct / $total * 100) : 0,
                ],
                'review' => $review,
                'mastery_deltas' => $masteryDeltas,
                'lesson_mastery' => [
                    'before' => $lessonMasteryBefore,
                    'after' => $this->bkt->averageMasteryForCompetencies($student, $lessonCompetencies),
                ],
                'subject_mastery' => [
                    'before' => $subjectMasteryBefore,
                    'after' => $this->bkt->averageMasteryForCompetencies($student, $subjectCompetencies),
                ],
            ];
        });
    }
}
