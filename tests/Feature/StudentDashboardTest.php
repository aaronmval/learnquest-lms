<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\StudentMastery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StudentDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $professor;

    private User $student;

    private int $classId;

    private int $atomsId;

    private int $molesId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professor = User::factory()->create(['role' => 'professor']);
        $this->student = User::factory()->create(['role' => 'student']);

        $subjectId = $this->actingAs($this->professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $this->classId = $this->actingAs($this->professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Class A',
            'section' => 'STEM A',
        ])->json('id');

        $this->atomsId = $this->competency($subjectId, 'Atomic Structure');
        $this->molesId = $this->competency($subjectId, 'Stoichiometry');

        $code = ClassRoom::find($this->classId)->code;
        $this->actingAs($this->student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();
    }

    private function competency(int $subjectId, string $name): int
    {
        return $this->actingAs($this->professor)
            ->postJson("/professor/subjects/{$subjectId}/competencies", ['name' => $name])
            ->json('id');
    }

    /**
     * A lesson with a 2-question quiz (atoms + moles); correct answer is index 0.
     *
     * @return array{0: int, 1: array<int, QuizQuestion>}
     */
    private function lessonQuiz(string $quarter): array
    {
        $post = ClassPost::create([
            'class_id' => $this->classId,
            'author_id' => $this->professor->id,
            'type' => 'lesson',
            'quarter' => $quarter,
            'title' => "Lesson {$quarter}",
            'attachment_path' => 'class-posts/lesson.pdf',
            'attachment_name' => 'lesson.pdf',
        ]);

        $quiz = Quiz::create(['class_post_id' => $post->id, 'model' => 'llama', 'generated_at' => now()]);

        $questions = [];
        foreach ([$this->atomsId, $this->molesId] as $i => $competencyId) {
            $questions[] = $quiz->questions()->create([
                'competency_id' => $competencyId,
                'question_text' => "Question {$i}",
                'choices' => ['Right', 'Wrong', 'Nope', 'No'],
                'correct_answer' => 'Right',
                'explanation' => 'Because.',
                'difficulty' => 'easy',
                'order_index' => $i,
            ]);
        }

        return [$post->id, $questions];
    }

    /** Submit an attempt: $answers maps question index => selected index (null = skipped). */
    private function submit(int $postId, array $questions, array $answers): void
    {
        $payload = [];
        foreach ($questions as $i => $question) {
            $payload[] = ['question_id' => $question->id, 'selected_index' => $answers[$i] ?? null];
        }

        $this->actingAs($this->student)
            ->postJson("/student/classes/{$this->classId}/posts/{$postId}/quiz/attempts", ['answers' => $payload])
            ->assertCreated();
    }

    private function subjectCompetency(array $json, int $competencyId): array
    {
        return collect($json['subjects'][0]['competencies'])->firstWhere('id', $competencyId);
    }

    public function test_unfiltered_mastery_matches_stored_bkt_mastery(): void
    {
        [$postId, $questions] = $this->lessonQuiz('1st Quarter');

        $this->submit($postId, $questions, [0 => 0, 1 => 1]); // atoms right, moles wrong
        $this->submit($postId, $questions, [0 => 0, 1 => 0]); // both right
        $this->submit($postId, $questions, [0 => 1]);         // atoms wrong, moles skipped

        $json = $this->actingAs($this->student)->getJson('/student/analytics')->assertOk()->json();

        foreach ([$this->atomsId, $this->molesId] as $competencyId) {
            $stored = StudentMastery::where('student_id', $this->student->id)
                ->where('competency_id', $competencyId)->value('current_mastery');

            $this->assertEqualsWithDelta(round($stored * 100, 1), $this->subjectCompetency($json, $competencyId)['mastery'], 0.05);
        }

        $this->assertSame(3, $this->subjectCompetency($json, $this->atomsId)['observations']);
        $this->assertSame(2, $this->subjectCompetency($json, $this->molesId)['observations']);
        $this->assertSame(5, $json['record_count']);
        $this->assertTrue($json['subjects'][0]['assessed']);
        $this->assertCount(1, $json['mastery_trend']);
    }

    public function test_quiz_scores_are_raw_percentages_per_month(): void
    {
        [$postId, $questions] = $this->lessonQuiz('1st Quarter');

        Carbon::setTestNow('2026-08-15 10:00:00');
        $this->submit($postId, $questions, [0 => 0, 1 => 1]); // 50%
        Carbon::setTestNow('2026-09-10 10:00:00');
        $this->submit($postId, $questions, [0 => 0, 1 => 0]); // 100%
        $this->submit($postId, $questions, [0 => 1, 1 => 1]); // 0%
        Carbon::setTestNow();

        $json = $this->actingAs($this->student)->getJson('/student/analytics')->assertOk()->json();

        $this->assertSame(['2026-08', '2026-09'], array_column($json['quiz_scores'], 'period'));
        $this->assertEquals(50.0, $json['quiz_scores'][0]['average']);
        $this->assertEquals(50.0, $json['quiz_scores'][1]['average']);
        $this->assertSame(2, $json['quiz_scores'][1]['attempts']);
        $this->assertSame(['Aug 2026', 'Sep 2026'], array_column($json['mastery_trend'], 'label'));
    }

    public function test_quarter_filter_only_counts_that_quarters_lessons(): void
    {
        [$q1Post, $q1Questions] = $this->lessonQuiz('1st Quarter');
        [$q2Post, $q2Questions] = $this->lessonQuiz('2nd Quarter');

        $this->submit($q1Post, $q1Questions, [0 => 0, 1 => 0]);
        $this->submit($q2Post, $q2Questions, [0 => 1, 1 => 1]);

        $json = $this->actingAs($this->student)->getJson('/student/analytics?quarter=1')->assertOk()->json();

        $this->assertSame('1st Quarter', $json['filters']['quarter']);
        $this->assertSame(2, $json['record_count']);
        $this->assertCount(1, $json['quiz_scores']);
        $this->assertEquals(100.0, $json['quiz_scores'][0]['average']);
        // Only the correct Q1 responses count, so mastery rose above the 30% prior.
        $this->assertGreaterThan(30, $this->subjectCompetency($json, $this->atomsId)['mastery']);
    }

    public function test_date_filter_bounds_scores_and_mastery(): void
    {
        [$postId, $questions] = $this->lessonQuiz('1st Quarter');

        Carbon::setTestNow('2026-08-15 10:00:00');
        $this->submit($postId, $questions, [0 => 0, 1 => 0]);
        Carbon::setTestNow('2026-09-10 10:00:00');
        $this->submit($postId, $questions, [0 => 1, 1 => 1]);
        Carbon::setTestNow();

        $json = $this->actingAs($this->student)
            ->getJson('/student/analytics?start=2026-09-01&end=2026-09-30')
            ->assertOk()->json();

        $this->assertSame(2, $json['record_count']);
        $this->assertSame(['2026-09'], array_column($json['quiz_scores'], 'period'));
        $this->assertSame(['2026-09'], array_column($json['mastery_trend'], 'period'));
        // Only the two wrong answers count, so mastery fell below the 30% prior.
        $this->assertLessThan(30, $this->subjectCompetency($json, $this->atomsId)['mastery']);
    }

    public function test_no_activity_uses_prior_and_other_classes_are_excluded(): void
    {
        $otherProfessor = User::factory()->create(['role' => 'professor']);
        $this->actingAs($otherProfessor)->postJson('/professor/classes', ['name' => 'Not mine']);

        $json = $this->actingAs($this->student)->getJson('/student/analytics')->assertOk()->json();

        $this->assertCount(1, $json['subjects']);
        $this->assertSame($this->classId, $json['subjects'][0]['class_id']);
        $this->assertFalse($json['subjects'][0]['assessed']);
        $this->assertEquals(30.0, $json['subjects'][0]['mastery']);
        $this->assertSame([], $json['quiz_scores']);
        $this->assertSame([], $json['mastery_trend']);
        $this->assertSame(0, $json['record_count']);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAs($this->student)->getJson('/student/analytics?quarter=5')->assertStatus(422);
        $this->actingAs($this->student)->getJson('/student/analytics?start=2026-09-10&end=2026-09-01')->assertStatus(422);
    }

    public function test_professor_is_redirected(): void
    {
        $this->actingAs($this->professor)->getJson('/student/analytics')->assertRedirect('/login');
    }
}
