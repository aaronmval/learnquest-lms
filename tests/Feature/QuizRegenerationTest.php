<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizFeedback;
use App\Models\User;
use App\Services\AI\LlamaService;
use App\Services\Documents\PdfTextExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class QuizRegenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests cover generation, validation and regeneration with a
        // single-request quiz; adaptive question banks are covered in
        // AdaptiveQuizTest / QuizStudioTest.
        config(['quiz.default_settings.adaptive' => false]);

        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldReceive('extractText')->andReturn('Lesson content about atomic structure.');
        $this->app->instance(PdfTextExtractorService::class, $extractor);
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int, 5: int}
     */
    private function classWithExistingQuizAndFeedback(int $feedbackCount = 1): array
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
        $question = $quiz->questions()->create([
            'competency_id' => $competencyId,
            'question_text' => 'Old question',
            'choices' => ['A', 'B'],
            'correct_answer' => 'A',
            'explanation' => 'Because A.',
            'difficulty' => 'easy',
        ]);

        for ($i = 0; $i < $feedbackCount; $i++) {
            $feedbackStudent = User::factory()->create(['role' => 'student']);
            $attempt = QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'student_id' => $feedbackStudent->id,
                'started_at' => now(),
                'submitted_at' => now(),
                'status' => 'submitted',
            ]);
            QuizFeedback::create([
                'quiz_attempt_id' => $attempt->id,
                'quiz_id' => $quiz->id,
                'student_id' => $feedbackStudent->id,
                'rating' => 2,
                'difficulty' => 'too_hard',
                'comment' => 'Too confusing.',
            ]);
        }

        return [$professor, $student, $classId, $post->id, $quiz->id, $competencyId];
    }

    public function test_owner_professor_sees_feedback_summary(): void
    {
        [$professor, , $classId, $postId] = $this->classWithExistingQuizAndFeedback(2);

        $res = $this->actingAs($professor)->getJson("/professor/classes/{$classId}/posts/{$postId}/quiz/feedback");
        $res->assertOk();
        $res->assertJsonPath('has_quiz', true);
        $res->assertJsonPath('summary.count', 2);
    }

    public function test_non_owning_professor_gets_404_on_feedback_view(): void
    {
        [, , $classId, $postId] = $this->classWithExistingQuizAndFeedback();
        $outsider = User::factory()->create(['role' => 'professor']);

        $this->actingAs($outsider)->getJson("/professor/classes/{$classId}/posts/{$postId}/quiz/feedback")
            ->assertNotFound();
    }

    public function test_feedback_view_for_lesson_with_no_quiz_yet_returns_200_not_error(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $classId = $this->actingAs($professor)->postJson('/professor/classes', ['name' => 'Class A'])->json('id');
        $postId = $this->actingAs($professor)->post("/professor/classes/{$classId}/posts", [
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'No Quiz Yet',
        ])->json('id');

        $res = $this->actingAs($professor)->getJson("/professor/classes/{$classId}/posts/{$postId}/quiz/feedback");
        $res->assertOk();
        $res->assertJsonPath('has_quiz', false);
        $res->assertJsonPath('summary', null);
    }

    public function test_regenerate_below_threshold_is_refused(): void
    {
        [$professor, , $classId, $postId] = $this->classWithExistingQuizAndFeedback(0);

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldNotReceive('chat');
        $this->app->instance(LlamaService::class, $llama);

        $res = $this->actingAs($professor)->postJson("/professor/classes/{$classId}/posts/{$postId}/quiz/regenerate");
        $res->assertStatus(422);
        $res->assertJsonPath('feedback_count', 0);

        $this->assertSame(1, Quiz::where('class_post_id', $postId)->count());
    }

    public function test_regenerate_success_creates_new_current_quiz_and_preserves_history(): void
    {
        [$professor, , $classId, $postId, $oldQuizId, $competencyId] = $this->classWithExistingQuizAndFeedback(1);

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->once()->andReturn([
            'content' => json_encode([
                'questions' => [[
                    'question' => 'New question',
                    'choices' => ['A', 'B', 'C', 'D'],
                    'correct_answer' => 'B',
                    'explanation' => 'Because B.',
                    'competency_id' => $competencyId,
                    'difficulty' => 'medium',
                ]],
            ]),
            'model' => 'llama-3.3-70b-instruct',
        ]);
        $this->app->instance(LlamaService::class, $llama);

        $res = $this->actingAs($professor)->postJson("/professor/classes/{$classId}/posts/{$postId}/quiz/regenerate");
        $res->assertCreated();
        $res->assertJsonMissingPath('questions'); // nothing question-shaped to leak

        $newQuizId = $res->json('quiz_id');
        $this->assertNotSame($oldQuizId, $newQuizId);

        $this->assertSame(2, Quiz::where('class_post_id', $postId)->count());
        $this->assertDatabaseHas('quizzes', ['id' => $oldQuizId]);
        $this->assertNotNull(Quiz::find($oldQuizId)->archived_at);
        $this->assertNull(Quiz::find($newQuizId)->archived_at);

        // A new student fetch now gets the new quiz's question.
        $student = User::factory()->create(['role' => 'student']);
        $code = ClassRoom::find($classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $quizRes = $this->actingAs($student)->getJson("/student/classes/{$classId}/posts/{$postId}/quiz");
        $quizRes->assertOk();
        $quizRes->assertJsonPath('quiz_id', $newQuizId);
        $quizRes->assertJsonPath('questions.0.text', 'New question');
    }

    public function test_non_owning_professor_gets_404_on_regenerate(): void
    {
        [, , $classId, $postId] = $this->classWithExistingQuizAndFeedback(1);
        $outsider = User::factory()->create(['role' => 'professor']);

        $this->actingAs($outsider)->postJson("/professor/classes/{$classId}/posts/{$postId}/quiz/regenerate")
            ->assertNotFound();
    }
}
