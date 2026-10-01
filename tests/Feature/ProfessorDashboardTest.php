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

class ProfessorDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $professor;

    private User $alice;

    private User $bob;

    private int $subjectId;

    private int $classId;

    private int $atomsId;

    private int $molesId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professor = User::factory()->create(['role' => 'professor']);
        $this->alice = User::factory()->create(['role' => 'student', 'name' => 'Alice Reyes']);
        $this->bob = User::factory()->create(['role' => 'student', 'name' => 'Bob Cruz']);

        $this->subjectId = $this->actingAs($this->professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $this->classId = $this->section('STEM A');

        $this->atomsId = $this->actingAs($this->professor)
            ->postJson("/professor/subjects/{$this->subjectId}/competencies", ['name' => 'Atomic Structure'])->json('id');
        $this->molesId = $this->actingAs($this->professor)
            ->postJson("/professor/subjects/{$this->subjectId}/competencies", ['name' => 'Stoichiometry'])->json('id');

        $this->join($this->alice, $this->classId);
        $this->join($this->bob, $this->classId);
    }

    private function section(string $name): int
    {
        return $this->actingAs($this->professor)->postJson("/professor/subjects/{$this->subjectId}/sections", [
            'name' => "Class {$name}",
            'section' => $name,
        ])->json('id');
    }

    private function join(User $student, int $classId): void
    {
        $code = ClassRoom::find($classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();
    }

    /**
     * A lesson with a 2-question quiz (atoms + moles); correct answer is index 0.
     *
     * @return array{0: int, 1: array<int, QuizQuestion>}
     */
    private function lessonQuiz(string $quarter, ?int $classId = null): array
    {
        $post = ClassPost::create([
            'class_id' => $classId ?? $this->classId,
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
    private function submit(User $student, int $postId, array $questions, array $answers, ?int $classId = null): void
    {
        $payload = [];
        foreach ($questions as $i => $question) {
            $payload[] = ['question_id' => $question->id, 'selected_index' => $answers[$i] ?? null];
        }

        $classId ??= $this->classId;
        $this->actingAs($student)
            ->postJson("/student/classes/{$classId}/posts/{$postId}/quiz/attempts", ['answers' => $payload])
            ->assertCreated();
    }

    private function competency(array $json, int $id): array
    {
        return collect($json['competencies'])->firstWhere('id', $id);
    }

    private function student(array $json, User $student): array
    {
        return collect($json['students'])->firstWhere('student_id', $student->id);
    }

    public function test_class_mastery_is_the_mean_of_students_stored_bkt_mastery(): void
    {
        [$postId, $questions] = $this->lessonQuiz('1st Quarter');

        $this->submit($this->alice, $postId, $questions, [0 => 0, 1 => 0]); // both right
        $this->submit($this->alice, $postId, $questions, [0 => 0, 1 => 1]); // atoms right, moles wrong
        $this->submit($this->bob, $postId, $questions, [0 => 1, 1 => 1]);   // both wrong

        $json = $this->actingAs($this->professor)->getJson('/professor/analytics')->assertOk()->json();

        foreach ([$this->atomsId, $this->molesId] as $competencyId) {
            $stored = StudentMastery::where('competency_id', $competencyId)->pluck('current_mastery');
            $this->assertCount(2, $stored);

            $row = $this->competency($json, $competencyId);
            $this->assertEqualsWithDelta(round($stored->avg() * 100, 1), $row['mastery'], 0.05);
            $this->assertSame(2, $row['students']);
            $this->assertSame(2, $row['assessed_students']);
        }

        $aliceAtoms = StudentMastery::where('student_id', $this->alice->id)->where('competency_id', $this->atomsId)->value('current_mastery');
        $alice = $this->student($json, $this->alice);
        $this->assertEqualsWithDelta(round($aliceAtoms * 100, 1), $alice['lessonMastery']['Atomic Structure'], 0.05);
        $this->assertSame('Atomic Structure', $alice['strength']);
        $this->assertSame('Stoichiometry', $alice['weakness']);
        $this->assertEquals(75.0, $alice['quizAverage']);

        // Strongest student first.
        $this->assertSame($this->alice->id, $json['students'][0]['student_id']);
        $this->assertSame(6, $json['record_count']);
        $this->assertSame(
            [['id' => $this->classId, 'label' => 'Chemistry - STEM A', 'subject_id' => $this->subjectId]],
            $json['sections'],
        );
    }

    public function test_unassessed_students_count_at_prior_and_are_flagged(): void
    {
        [$postId, $questions] = $this->lessonQuiz('1st Quarter');
        $this->submit($this->alice, $postId, $questions, [0 => 0, 1 => 0]);

        $json = $this->actingAs($this->professor)->getJson('/professor/analytics')->assertOk()->json();

        $bob = $this->student($json, $this->bob);
        $this->assertFalse($bob['assessed']);
        $this->assertEquals(30.0, $bob['masteryScore']);
        $this->assertNull($bob['quizAverage']);
        $this->assertNull($bob['strength']);
        $this->assertSame($this->bob->id, $json['students'][1]['student_id']);

        $atoms = $this->competency($json, $this->atomsId);
        $this->assertSame(1, $atoms['assessed_students']);
        $aliceAtoms = StudentMastery::where('student_id', $this->alice->id)->where('competency_id', $this->atomsId)->value('current_mastery');
        $this->assertEqualsWithDelta(round(($aliceAtoms + 0.30) / 2 * 100, 1), $atoms['mastery'], 0.05);
    }

    public function test_quiz_scores_are_raw_monthly_percentages(): void
    {
        [$postId, $questions] = $this->lessonQuiz('1st Quarter');

        Carbon::setTestNow('2026-08-15 10:00:00');
        $this->submit($this->alice, $postId, $questions, [0 => 0, 1 => 1]); // 50%
        Carbon::setTestNow('2026-09-10 10:00:00');
        $this->submit($this->alice, $postId, $questions, [0 => 0, 1 => 0]); // 100%
        $this->submit($this->bob, $postId, $questions, [0 => 1, 1 => 1]);   // 0%
        Carbon::setTestNow();

        $json = $this->actingAs($this->professor)->getJson('/professor/analytics')->assertOk()->json();

        $this->assertSame(['2026-08', '2026-09'], array_column($json['quiz_scores'], 'period'));
        $this->assertEquals(50.0, $json['quiz_scores'][0]['average']);
        $this->assertEquals(50.0, $json['quiz_scores'][1]['average']);
        $this->assertSame(2, $json['quiz_scores'][1]['attempts']);
    }

    public function test_quarter_and_date_filters_bound_responses(): void
    {
        [$q1Post, $q1Questions] = $this->lessonQuiz('1st Quarter');
        [$q2Post, $q2Questions] = $this->lessonQuiz('2nd Quarter');

        Carbon::setTestNow('2026-08-15 10:00:00');
        $this->submit($this->alice, $q1Post, $q1Questions, [0 => 0, 1 => 0]);
        Carbon::setTestNow('2026-09-10 10:00:00');
        $this->submit($this->alice, $q2Post, $q2Questions, [0 => 1, 1 => 1]);
        Carbon::setTestNow();

        $q1 = $this->actingAs($this->professor)->getJson('/professor/analytics?quarter=1')->assertOk()->json();
        $this->assertSame('1st Quarter', $q1['filters']['quarter']);
        $this->assertSame(2, $q1['record_count']);
        $this->assertGreaterThan(30, $this->student($q1, $this->alice)['lessonMastery']['Atomic Structure']);

        $sept = $this->actingAs($this->professor)
            ->getJson('/professor/analytics?start=2026-09-01&end=2026-09-30')->assertOk()->json();
        $this->assertSame(2, $sept['record_count']);
        $this->assertSame(['2026-09'], array_column($sept['quiz_scores'], 'period'));
        $this->assertLessThan(30, $this->student($sept, $this->alice)['lessonMastery']['Atomic Structure']);
    }

    public function test_section_filter_and_ownership(): void
    {
        $otherClassId = $this->section('STEM B');
        $carl = User::factory()->create(['role' => 'student']);
        $this->join($carl, $otherClassId);

        $json = $this->actingAs($this->professor)->getJson("/professor/analytics?class_id={$otherClassId}")->assertOk()->json();
        $this->assertSame([$carl->id], array_column($json['students'], 'student_id'));
        $this->assertCount(2, $json['sections']);

        $stranger = User::factory()->create(['role' => 'professor']);
        $this->actingAs($stranger)->getJson("/professor/analytics?class_id={$this->classId}")->assertNotFound();

        $strangerJson = $this->actingAs($stranger)->getJson('/professor/analytics')->assertOk()->json();
        $this->assertSame([], $strangerJson['sections']);
        $this->assertSame([], $strangerJson['students']);
    }

    public function test_collaborator_sees_the_subjects_sections(): void
    {
        $collaborator = User::factory()->create(['role' => 'professor']);
        $this->actingAs($this->professor)
            ->postJson("/professor/subjects/{$this->subjectId}/collaborators", ['email' => $collaborator->email])
            ->assertSuccessful();

        $json = $this->actingAs($collaborator)->getJson("/professor/analytics?class_id={$this->classId}")->assertOk()->json();
        $this->assertCount(2, $json['students']);
    }

    public function test_subject_filter_scopes_everything_to_one_subject(): void
    {
        // A second subject taught by the same professor, with its own section, competency and student.
        $physicsId = $this->actingAs($this->professor)->postJson('/professor/subjects', ['name' => 'Physics'])->json('id');
        $physicsClassId = $this->actingAs($this->professor)->postJson("/professor/subjects/{$physicsId}/sections", [
            'name' => 'Physics - STEM A',
            'section' => 'STEM A',
        ])->json('id');
        $forcesId = $this->actingAs($this->professor)
            ->postJson("/professor/subjects/{$physicsId}/competencies", ['name' => 'Forces'])->json('id');
        $carla = User::factory()->create(['role' => 'student', 'name' => 'Carla Diaz']);
        $this->join($carla, $physicsClassId);

        [$postId, $questions] = $this->lessonQuiz('1st Quarter');
        $this->submit($this->alice, $postId, $questions, [0, 0]);

        $all = $this->actingAs($this->professor)->getJson('/professor/analytics')->assertOk()->json();
        $this->assertSame(['Chemistry', 'Physics'], array_column($all['subjects'], 'name'));
        $this->assertCount(3, $all['competencies']);
        $this->assertSame(3, $all['student_count']);
        $this->assertSame($physicsId, collect($all['sections'])->firstWhere('id', $physicsClassId)['subject_id']);

        $chemistry = $this->actingAs($this->professor)
            ->getJson("/professor/analytics?subject_id={$this->subjectId}")->assertOk()->json();
        $this->assertSame($this->subjectId, $chemistry['filters']['subject_id']);
        $this->assertEqualsCanonicalizing([$this->atomsId, $this->molesId], array_column($chemistry['competencies'], 'id'));
        // One subject in scope, so competency names carry no subject suffix.
        $this->assertSame('Atomic Structure', $this->competency($chemistry, $this->atomsId)['name']);
        $this->assertSame(2, $chemistry['student_count']);
        $this->assertSame(2, $chemistry['record_count']);
        $this->assertNotEmpty($chemistry['quiz_scores']);
        // The filter lists stay complete so the professor can switch subject.
        $this->assertCount(2, $chemistry['subjects']);
        $this->assertCount(2, $chemistry['sections']);

        $physics = $this->actingAs($this->professor)
            ->getJson("/professor/analytics?subject_id={$physicsId}")->assertOk()->json();
        $this->assertSame([$forcesId], array_column($physics['competencies'], 'id'));
        $this->assertSame(['Carla Diaz'], array_column($physics['students'], 'name'));
        $this->assertSame(0, $physics['record_count']);
        $this->assertSame([], $physics['quiz_scores']);

        // A section that isn't part of the chosen subject, and other professors' subjects, are not found.
        $this->actingAs($this->professor)
            ->getJson("/professor/analytics?subject_id={$physicsId}&class_id={$this->classId}")->assertNotFound();

        $stranger = User::factory()->create(['role' => 'professor']);
        $this->actingAs($stranger)->getJson("/professor/analytics?subject_id={$this->subjectId}")->assertNotFound();
        $this->actingAs($stranger)->getJson("/professor/analytics/insights?subject_id={$this->subjectId}")->assertNotFound();
    }

    public function test_archived_sections_are_excluded(): void
    {
        ClassRoom::find($this->classId)->update(['archived_at' => now()]);

        $json = $this->actingAs($this->professor)->getJson('/professor/analytics')->assertOk()->json();
        $this->assertSame([], $json['sections']);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAs($this->professor)->getJson('/professor/analytics?quarter=5')->assertStatus(422);
        $this->actingAs($this->professor)->getJson('/professor/analytics?start=2026-09-10&end=2026-09-01')->assertStatus(422);
    }

    public function test_student_is_redirected(): void
    {
        $this->actingAs($this->alice)->getJson('/professor/analytics')->assertRedirect('/login');
        $this->actingAs($this->alice)->getJson('/professor/analytics/insights')->assertRedirect('/login');
    }
}
