<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Quiz;
use App\Models\StudentMastery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentMasteryTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_mastery_averages_all_subject_competencies(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $classId = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Class A',
            'section' => 'STEM A',
        ])->json('id');

        $competencyAId = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/competencies", [
            'name' => 'Atomic Structure',
        ])->json('id');
        $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/competencies", [
            'name' => 'Stoichiometry',
        ])->json('id');

        $code = ClassRoom::find($classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        // Only one of the two competencies has an observed mastery record.
        StudentMastery::create([
            'student_id' => $student->id,
            'competency_id' => $competencyAId,
            'initial_mastery' => 0.30,
            'current_mastery' => 0.80,
            'p_l0' => 0.30,
            'p_t' => 0.15,
            'p_g' => 0.25,
            'p_s' => 0.10,
        ]);

        $res = $this->actingAs($student)->getJson("/student/classes/{$classId}/mastery");
        $res->assertOk();
        $res->assertJsonPath('competency_count', 2);
        // Average of 0.80 (observed) and 0.30 (default P(L0) prior, unobserved).
        $this->assertEqualsWithDelta(0.55, $res->json('average_mastery'), 1e-9);
    }

    public function test_class_mastery_is_null_when_no_competencies_defined(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);

        $classId = $this->actingAs($professor)->postJson('/professor/classes', ['name' => 'Class A'])->json('id');
        $code = ClassRoom::find($classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $res = $this->actingAs($student)->getJson("/student/classes/{$classId}/mastery");
        $res->assertOk();
        $res->assertJsonPath('average_mastery', null);
        $res->assertJsonPath('competency_count', 0);
    }

    public function test_class_mastery_unenrolled_student_gets_404(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $outsider = User::factory()->create(['role' => 'student']);

        $classId = $this->actingAs($professor)->postJson('/professor/classes', ['name' => 'Class A'])->json('id');

        $this->actingAs($outsider)->getJson("/student/classes/{$classId}/mastery")->assertNotFound();
    }

    public function test_lesson_mastery_reflects_only_the_quizzes_competencies(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $classId = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Class A',
            'section' => 'STEM A',
        ])->json('id');

        $testedCompetencyId = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/competencies", [
            'name' => 'Atomic Structure',
        ])->json('id');
        $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/competencies", [
            'name' => 'Stoichiometry',
        ])->json('id');

        $code = ClassRoom::find($classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $post = ClassPost::create([
            'class_id' => $classId,
            'author_id' => $professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Atomic Structure',
        ]);

        $quiz = Quiz::create(['class_post_id' => $post->id, 'generated_at' => now()]);
        $quiz->questions()->create([
            'competency_id' => $testedCompetencyId,
            'question_text' => 'Q',
            'choices' => ['A', 'B'],
            'correct_answer' => 'A',
            'explanation' => 'Because A.',
            'difficulty' => 'easy',
        ]);

        StudentMastery::create([
            'student_id' => $student->id,
            'competency_id' => $testedCompetencyId,
            'initial_mastery' => 0.30,
            'current_mastery' => 0.90,
            'p_l0' => 0.30,
            'p_t' => 0.15,
            'p_g' => 0.25,
            'p_s' => 0.10,
        ]);

        $res = $this->actingAs($student)->getJson("/student/classes/{$classId}/posts/{$post->id}/mastery");
        $res->assertOk();
        $res->assertJsonPath('competency_count', 1);
        $this->assertEqualsWithDelta(0.90, $res->json('lesson_mastery'), 1e-9);
    }

    public function test_lesson_mastery_is_null_when_no_quiz_generated_yet(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);

        $classId = $this->actingAs($professor)->postJson('/professor/classes', ['name' => 'Class A'])->json('id');
        $code = ClassRoom::find($classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $post = ClassPost::create([
            'class_id' => $classId,
            'author_id' => $professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'No Quiz Yet',
        ]);

        $res = $this->actingAs($student)->getJson("/student/classes/{$classId}/posts/{$post->id}/mastery");
        $res->assertOk();
        $res->assertJsonPath('lesson_mastery', null);
        $res->assertJsonPath('competency_count', 0);
    }
}
