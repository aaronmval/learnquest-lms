<?php

namespace Tests\Feature;

use App\Exceptions\AI\LlamaApiException;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Quiz;
use App\Models\Subject;
use App\Models\User;
use App\Services\AI\LlamaService;
use App\Services\Documents\PdfTextExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class QuizGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldReceive('extractText')->andReturn('Lesson content about atomic structure.');
        $this->app->instance(PdfTextExtractorService::class, $extractor);
    }

    /**
     * @return array{0: User, 1: int, 2: int, 3: Subject}
     */
    private function enrolledStudentWithLessonAndCompetencies(): array
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $subject = Subject::find($subjectId);

        $classId = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Class A',
            'section' => 'STEM A',
        ])->json('id');

        $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/competencies", [
            'name' => 'Atomic Structure',
        ])->assertCreated();

        $code = ClassRoom::find($classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $postId = $this->actingAs($professor)->post("/professor/classes/{$classId}/posts", [
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Atomic Structure',
            'attachment' => $pdf,
        ])->json('id');

        return [$student, $classId, $postId, $subject];
    }

    private function fakeLlamaQuizResponse(array $competencyIds): array
    {
        return [
            'content' => json_encode([
                'questions' => [
                    [
                        'question' => 'Which particle has a negative charge?',
                        'choices' => ['Proton', 'Neutron', 'Electron', 'Positron'],
                        'correct_answer' => 'Electron',
                        'explanation' => 'Electrons are negatively charged.',
                        'competency_id' => $competencyIds[0],
                        'difficulty' => 'easy',
                    ],
                ],
            ]),
            'model' => 'llama-3.3-70b-instruct',
        ];
    }

    public function test_quiz_endpoint_generates_and_caches(): void
    {
        Storage::fake('local');
        [$student, $classId, $postId, $subject] = $this->enrolledStudentWithLessonAndCompetencies();
        $competencyIds = $subject->competencies()->pluck('id')->all();

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->once()->andReturn($this->fakeLlamaQuizResponse($competencyIds));
        $this->app->instance(LlamaService::class, $llama);

        $res = $this->actingAs($student)->getJson("/student/classes/{$classId}/posts/{$postId}/quiz");
        $res->assertCreated();
        $res->assertJsonPath('cached', false);
        $res->assertJsonCount(1, 'questions');

        foreach (['correct_answer', 'explanation', 'competency_id', 'difficulty'] as $forbiddenField) {
            $res->assertJsonMissingPath("questions.0.{$forbiddenField}");
        }

        $this->assertDatabaseCount('quizzes', 1);

        $res2 = $this->actingAs($student)->getJson("/student/classes/{$classId}/posts/{$postId}/quiz");
        $res2->assertOk();
        $res2->assertJsonPath('cached', true);
    }

    public function test_unenrolled_student_gets_404(): void
    {
        Storage::fake('local');
        [, $classId, $postId] = $this->enrolledStudentWithLessonAndCompetencies();
        $outsider = User::factory()->create(['role' => 'student']);

        $this->actingAs($outsider)->getJson("/student/classes/{$classId}/posts/{$postId}/quiz")
            ->assertNotFound();
    }

    public function test_announcement_post_returns_404(): void
    {
        Storage::fake('local');
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);

        $classId = $this->actingAs($professor)->postJson('/professor/classes', ['name' => 'Class A'])->json('id');
        $code = ClassRoom::find($classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $postId = $this->actingAs($professor)->post("/professor/classes/{$classId}/posts", [
            'type' => 'announcement',
            'quarter' => '1st Quarter',
            'title' => 'Welcome',
        ])->json('id');

        $this->actingAs($student)->getJson("/student/classes/{$classId}/posts/{$postId}/quiz")
            ->assertNotFound();
    }

    public function test_class_with_no_linked_subject_returns_a_friendly_500(): void
    {
        Storage::fake('local');
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);

        // Created via the standalone Classes flow — no subject_id, so no
        // competency pool exists to tag questions with.
        $classId = $this->actingAs($professor)->postJson('/professor/classes', ['name' => 'Class A'])->json('id');
        $code = ClassRoom::find($classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $postId = $this->actingAs($professor)->post("/professor/classes/{$classId}/posts", [
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Atomic Structure',
            'attachment' => $pdf,
        ])->json('id');

        $this->actingAs($student)->getJson("/student/classes/{$classId}/posts/{$postId}/quiz")
            ->assertStatus(500);

        $this->assertDatabaseCount('quizzes', 0);
    }

    public function test_llama_failure_returns_a_friendly_502(): void
    {
        Storage::fake('local');
        [$student, $classId, $postId] = $this->enrolledStudentWithLessonAndCompetencies();

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->once()->andThrow(new LlamaApiException('boom'));
        $this->app->instance(LlamaService::class, $llama);

        $res = $this->actingAs($student)->getJson("/student/classes/{$classId}/posts/{$postId}/quiz");
        $res->assertStatus(502);
        $this->assertDatabaseCount('quizzes', 0);
    }

    public function test_question_order_is_shuffled_on_each_open(): void
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
            'title' => 'Atoms',
            'attachment_path' => 'class-posts/atoms.pdf',
            'attachment_name' => 'atoms.pdf',
        ]);
        $quiz = Quiz::create(['class_post_id' => $post->id, 'model' => 'llama', 'generated_at' => now()]);

        $storedIds = [];
        foreach (range(0, 7) as $i) {
            $storedIds[] = $quiz->questions()->create([
                'competency_id' => $competencyId,
                'question_text' => "Question {$i}",
                'choices' => ['A', 'B', 'C', 'D'],
                'correct_answer' => 'A',
                'explanation' => 'Because.',
                'difficulty' => 'easy',
                'order_index' => $i,
            ])->id;
        }

        $orders = [];
        foreach (range(1, 10) as $_) {
            $ids = $this->actingAs($student)
                ->getJson("/student/classes/{$classId}/posts/{$post->id}/quiz")
                ->assertOk()
                ->json('questions.*.id');

            // Every open serves the same questions, just reordered.
            $this->assertEqualsCanonicalizing($storedIds, $ids);
            $orders[] = implode(',', $ids);
        }

        // With 8 questions (40,320 orders), 10 identical opens would be vanishingly unlikely.
        $this->assertGreaterThan(1, count(array_unique($orders)));
    }
}
