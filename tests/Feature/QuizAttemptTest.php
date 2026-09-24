<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizAttemptTest extends TestCase
{
    use RefreshDatabase;

    private function enrolledStudentWithGeneratedQuiz(): array
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $classId = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Class A',
            'section' => 'STEM A',
        ])->json('id');

        $competencyId = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/competencies", [
            'name' => 'Atomic Structure',
        ])->json('id');

        $code = ClassRoom::find($classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $postId = ClassPost::create([
            'class_id' => $classId,
            'author_id' => $professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Atomic Structure',
            'attachment_path' => 'class-posts/1/lesson.pdf',
            'attachment_name' => 'lesson.pdf',
        ])->id;

        $quiz = Quiz::create([
            'class_post_id' => $postId,
            'model' => 'llama-3.3-70b-instruct',
            'generated_at' => now(),
        ]);

        $question = $quiz->questions()->create([
            'competency_id' => $competencyId,
            'question_text' => 'Which particle has a negative charge?',
            'choices' => ['Proton', 'Neutron', 'Electron', 'Positron'],
            'correct_answer' => 'Electron',
            'explanation' => 'Electrons are negatively charged.',
            'difficulty' => 'easy',
            'order_index' => 0,
        ]);

        return [$student, $classId, $postId, $question, $competencyId];
    }

    public function test_full_submit_flow_grades_and_updates_mastery(): void
    {
        [$student, $classId, $postId, $question, $competencyId] = $this->enrolledStudentWithGeneratedQuiz();

        $res = $this->actingAs($student)->postJson("/student/classes/{$classId}/posts/{$postId}/quiz/attempts", [
            'answers' => [
                ['question_id' => $question->id, 'selected_index' => 2], // "Electron" — correct
            ],
        ]);

        $res->assertCreated();
        $res->assertJsonPath('score.correct', 1);
        $res->assertJsonPath('score.total', 1);
        $res->assertJsonPath('review.0.is_correct', true);
        $res->assertJsonPath('review.0.correct_index', 2);

        $this->assertDatabaseCount('quiz_attempts', 1);
        $this->assertDatabaseCount('quiz_answers', 1);

        $this->assertDatabaseHas('student_mastery', [
            'student_id' => $student->id,
            'competency_id' => $competencyId,
        ]);
    }

    public function test_missing_quiz_returns_404(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);

        $classId = $this->actingAs($professor)->postJson('/professor/classes', ['name' => 'Class A'])->json('id');
        $code = ClassRoom::find($classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $postId = $this->actingAs($professor)->post("/professor/classes/{$classId}/posts", [
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'No Quiz Yet',
        ])->json('id');

        $this->actingAs($student)->postJson("/student/classes/{$classId}/posts/{$postId}/quiz/attempts", [
            'answers' => [['question_id' => 1, 'selected_index' => 0]],
        ])->assertNotFound();
    }

    public function test_unenrolled_student_gets_404(): void
    {
        [, $classId, $postId, $question] = $this->enrolledStudentWithGeneratedQuiz();
        $outsider = User::factory()->create(['role' => 'student']);

        $this->actingAs($outsider)->postJson("/student/classes/{$classId}/posts/{$postId}/quiz/attempts", [
            'answers' => [['question_id' => $question->id, 'selected_index' => 2]],
        ])->assertNotFound();
    }
}
