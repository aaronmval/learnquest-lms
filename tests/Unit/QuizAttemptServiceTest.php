<?php

namespace Tests\Unit;

use App\Models\ClassPost;
use App\Models\Quiz;
use App\Models\StudentMastery;
use App\Models\User;
use App\Services\Bkt\BayesianKnowledgeTracingService;
use App\Services\Quiz\QuizAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizAttemptServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeQuizWithTwoQuestions(): array
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $subject = $professor->ownedSubjects()->create(['name' => 'Chemistry']);
        $class = $subject->sections()->create(['professor_id' => $professor->id, 'name' => 'STEM A']);
        $competency = $subject->competencies()->create(['name' => 'Atomic Structure']);

        $post = ClassPost::create([
            'class_id' => $class->id,
            'author_id' => $professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Atomic Structure',
            'attachment_path' => 'class-posts/1/lesson.pdf',
            'attachment_name' => 'lesson.pdf',
        ]);

        $quiz = Quiz::create([
            'class_post_id' => $post->id,
            'model' => 'llama-3.3-70b-instruct',
            'generated_at' => now(),
        ]);

        $q1 = $quiz->questions()->create([
            'competency_id' => $competency->id,
            'question_text' => 'Q1',
            'choices' => ['A', 'B', 'C', 'D'],
            'correct_answer' => 'B',
            'explanation' => 'Because B.',
            'difficulty' => 'easy',
            'order_index' => 0,
        ]);

        $q2 = $quiz->questions()->create([
            'competency_id' => $competency->id,
            'question_text' => 'Q2',
            'choices' => ['A', 'B', 'C', 'D'],
            'correct_answer' => 'C',
            'explanation' => 'Because C.',
            'difficulty' => 'easy',
            'order_index' => 1,
        ]);

        $student = User::factory()->create(['role' => 'student']);

        return [$quiz->fresh(), $q1, $q2, $student, $competency];
    }

    public function test_all_correct_submission_raises_mastery(): void
    {
        [$quiz, $q1, $q2, $student, $competency] = $this->makeQuizWithTwoQuestions();
        $service = new QuizAttemptService(new BayesianKnowledgeTracingService);

        $result = $service->submit($quiz, $student, [
            ['question_id' => $q1->id, 'selected_index' => 1], // "B" — correct
            ['question_id' => $q2->id, 'selected_index' => 2], // "C" — correct
        ]);

        $this->assertSame(2, $result['score']['correct']);
        $this->assertSame(0, $result['score']['incorrect']);
        $this->assertSame(0, $result['score']['skipped']);

        $mastery = StudentMastery::where('student_id', $student->id)
            ->where('competency_id', $competency->id)->first();

        $this->assertGreaterThan((float) config('bkt.default_pl0'), $mastery->current_mastery);
        $this->assertSame(2, $mastery->observations_count);
    }

    public function test_skipped_question_fires_no_bkt_update_when_its_the_only_one(): void
    {
        [$quiz, $q1, $q2, $student, $competency] = $this->makeQuizWithTwoQuestions();
        $service = new QuizAttemptService(new BayesianKnowledgeTracingService);

        $service->submit($quiz, $student, [
            ['question_id' => $q1->id, 'selected_index' => null],
            ['question_id' => $q2->id, 'selected_index' => null],
        ]);

        $this->assertDatabaseCount('student_mastery', 0);
    }

    public function test_review_payload_matches_underlying_question_data(): void
    {
        [$quiz, $q1, $q2, $student] = $this->makeQuizWithTwoQuestions();
        $service = new QuizAttemptService(new BayesianKnowledgeTracingService);

        $result = $service->submit($quiz, $student, [
            ['question_id' => $q1->id, 'selected_index' => 0], // "A" — incorrect (correct is "B")
            ['question_id' => $q2->id, 'selected_index' => 2], // "C" — correct
        ]);

        $review = collect($result['review'])->keyBy('question_id');

        $this->assertFalse($review[$q1->id]['is_correct']);
        $this->assertSame(1, $review[$q1->id]['correct_index']); // "B" is index 1
        $this->assertSame('Because B.', $review[$q1->id]['explanation']);

        $this->assertTrue($review[$q2->id]['is_correct']);
        $this->assertSame(2, $review[$q2->id]['correct_index']); // "C" is index 2
    }

    public function test_out_of_range_selected_index_is_treated_as_skipped(): void
    {
        [$quiz, $q1, $q2, $student] = $this->makeQuizWithTwoQuestions();
        $service = new QuizAttemptService(new BayesianKnowledgeTracingService);

        $result = $service->submit($quiz, $student, [
            ['question_id' => $q1->id, 'selected_index' => 99],
            ['question_id' => $q2->id, 'selected_index' => 2],
        ]);

        $this->assertSame(1, $result['score']['correct']);
        $this->assertSame(1, $result['score']['skipped']);
        $this->assertSame(0, $result['score']['incorrect']);
    }

    /**
     * Lesson mastery must reflect only the competency this lesson's quiz
     * tests, while subject mastery must average across every competency
     * defined for the subject — including one this quiz never touches.
     */
    public function test_lesson_mastery_and_subject_mastery_are_computed_differently(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $subject = $professor->ownedSubjects()->create(['name' => 'Chemistry']);
        $class = $subject->sections()->create(['professor_id' => $professor->id, 'name' => 'STEM A']);

        $testedCompetency = $subject->competencies()->create(['name' => 'Atomic Structure']);
        $untestedCompetency = $subject->competencies()->create(['name' => 'Stoichiometry']);

        $post = ClassPost::create([
            'class_id' => $class->id,
            'author_id' => $professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Atomic Structure',
            'attachment_path' => 'class-posts/1/lesson.pdf',
            'attachment_name' => 'lesson.pdf',
        ]);

        $quiz = Quiz::create([
            'class_post_id' => $post->id,
            'model' => 'llama-3.3-70b-instruct',
            'generated_at' => now(),
        ]);

        $question = $quiz->questions()->create([
            'competency_id' => $testedCompetency->id,
            'question_text' => 'Q1',
            'choices' => ['A', 'B', 'C', 'D'],
            'correct_answer' => 'B',
            'explanation' => 'Because B.',
            'difficulty' => 'easy',
            'order_index' => 0,
        ]);

        $student = User::factory()->create(['role' => 'student']);
        $service = new QuizAttemptService(new BayesianKnowledgeTracingService);

        $result = $service->submit($quiz, $student, [
            ['question_id' => $question->id, 'selected_index' => 1], // "B" — correct
        ]);

        $defaultPl0 = (float) config('bkt.default_pl0');
        $testedAfter = (float) StudentMastery::where('student_id', $student->id)
            ->where('competency_id', $testedCompetency->id)->first()->current_mastery;

        // Lesson mastery: before/after both equal the tested competency's
        // own mastery (only one competency in this lesson's quiz).
        $this->assertEqualsWithDelta($defaultPl0, $result['lesson_mastery']['before'], 1e-9);
        $this->assertEqualsWithDelta($testedAfter, $result['lesson_mastery']['after'], 1e-9);
        $this->assertGreaterThan($result['lesson_mastery']['before'], $result['lesson_mastery']['after']);

        // Subject mastery: averages the tested competency with the
        // untested one (still sitting at the default P(L0) prior).
        $this->assertEqualsWithDelta($defaultPl0, $result['subject_mastery']['before'], 1e-9);
        $this->assertEqualsWithDelta(
            ($testedAfter + $defaultPl0) / 2,
            $result['subject_mastery']['after'],
            1e-9,
        );

        // The untested competency must never get a mastery row just from
        // being averaged into the subject mastery display.
        $this->assertDatabaseMissing('student_mastery', [
            'student_id' => $student->id,
            'competency_id' => $untestedCompetency->id,
        ]);
    }
}
