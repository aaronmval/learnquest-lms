<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizFeedbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: int, 2: int, 3: QuizAttempt}
     */
    private function enrolledStudentWithSubmittedAttempt(): array
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

        $post = ClassPost::create([
            'class_id' => $classId,
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
        $quiz->questions()->create([
            'competency_id' => $competencyId,
            'question_text' => 'Q',
            'choices' => ['A', 'B'],
            'correct_answer' => 'A',
            'explanation' => 'Because A.',
            'difficulty' => 'easy',
        ]);

        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'started_at' => now(),
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);

        return [$student, $classId, $post->id, $attempt];
    }

    private function feedbackUrl(int $classId, int $postId, int $attemptId): string
    {
        return "/student/classes/{$classId}/posts/{$postId}/quiz/attempts/{$attemptId}/feedback";
    }

    public function test_student_can_submit_feedback_for_their_own_attempt(): void
    {
        [$student, $classId, $postId, $attempt] = $this->enrolledStudentWithSubmittedAttempt();

        $res = $this->actingAs($student)->postJson($this->feedbackUrl($classId, $postId, $attempt->id), [
            'rating' => 4,
            'difficulty' => 'just_right',
            'comment' => 'Pretty good quiz.',
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('quiz_feedback', [
            'quiz_attempt_id' => $attempt->id,
            'quiz_id' => $attempt->quiz_id,
            'student_id' => $student->id,
            'rating' => 4,
            'difficulty' => 'just_right',
            'comment' => 'Pretty good quiz.',
        ]);
    }

    public function test_duplicate_submission_for_the_same_attempt_is_rejected(): void
    {
        [$student, $classId, $postId, $attempt] = $this->enrolledStudentWithSubmittedAttempt();

        $payload = ['rating' => 5, 'difficulty' => 'too_easy'];
        $this->actingAs($student)->postJson($this->feedbackUrl($classId, $postId, $attempt->id), $payload)
            ->assertCreated();

        $this->actingAs($student)->postJson($this->feedbackUrl($classId, $postId, $attempt->id), $payload)
            ->assertStatus(422);

        $this->assertSame(1, QuizFeedback::count());
    }

    public function test_student_cannot_submit_feedback_for_someone_elses_attempt(): void
    {
        [, $classId, $postId, $attempt] = $this->enrolledStudentWithSubmittedAttempt();

        $otherStudent = User::factory()->create(['role' => 'student']);
        $code = ClassRoom::find($classId)->code;
        $this->actingAs($otherStudent)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $this->actingAs($otherStudent)->postJson($this->feedbackUrl($classId, $postId, $attempt->id), [
            'rating' => 3,
            'difficulty' => 'just_right',
        ])->assertNotFound();
    }

    public function test_validation_rejects_out_of_range_rating_and_invalid_difficulty(): void
    {
        [$student, $classId, $postId, $attempt] = $this->enrolledStudentWithSubmittedAttempt();

        $this->actingAs($student)->postJson($this->feedbackUrl($classId, $postId, $attempt->id), [
            'rating' => 6,
            'difficulty' => 'just_right',
        ])->assertStatus(422);

        $this->actingAs($student)->postJson($this->feedbackUrl($classId, $postId, $attempt->id), [
            'rating' => 3,
            'difficulty' => 'somewhat_hard',
        ])->assertStatus(422);
    }
}
