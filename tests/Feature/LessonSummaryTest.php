<?php

namespace Tests\Feature;

use App\Exceptions\AI\LlamaApiException;
use App\Models\ClassRoom;
use App\Models\User;
use App\Services\AI\LlamaService;
use App\Services\Documents\PdfTextExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class LessonSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The uploaded test PDFs are fake byte blobs, not real PDF documents,
        // so extraction is stubbed out for every test in this class.
        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldReceive('extractText')->andReturn('Lesson content about stoichiometry.');
        $this->app->instance(PdfTextExtractorService::class, $extractor);
    }

    private function enrolledStudentWithLesson(): array
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);

        $class = $this->actingAs($professor)->postJson('/professor/classes', ['name' => 'Class A']);
        $classId = $class->json('id');
        $code = ClassRoom::find($classId)->code;

        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $post = $this->actingAs($professor)->post("/professor/classes/{$classId}/posts", [
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Stoichiometry',
            'attachment' => $pdf,
        ])->json();

        return [$student, $classId, $post['id']];
    }

    public function test_summary_endpoint_generates_and_caches_a_summary(): void
    {
        Storage::fake('local');
        [$student, $classId, $postId] = $this->enrolledStudentWithLesson();

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->once()->andReturn([
            'content' => json_encode([
                'overview' => 'A recap of the lesson.',
                'key_points' => ['Point one', 'Point two'],
            ]),
            'model' => 'llama-3.3-70b-instruct',
        ]);
        $this->app->instance(LlamaService::class, $llama);

        $res = $this->actingAs($student)->getJson("/student/classes/{$classId}/posts/{$postId}/summary");
        $res->assertCreated();
        $res->assertJsonPath('overview', 'A recap of the lesson.');
        $res->assertJsonPath('model', 'llama-3.3-70b-instruct');
        $res->assertJsonPath('cached', false);

        $this->assertDatabaseCount('lesson_summaries', 1);

        // Second request should be served from cache without calling Llama again.
        $res2 = $this->actingAs($student)->getJson("/student/classes/{$classId}/posts/{$postId}/summary");
        $res2->assertOk();
        $res2->assertJsonPath('cached', true);
    }

    public function test_unenrolled_student_gets_404(): void
    {
        Storage::fake('local');
        [, $classId, $postId] = $this->enrolledStudentWithLesson();
        $outsider = User::factory()->create(['role' => 'student']);

        $this->actingAs($outsider)->getJson("/student/classes/{$classId}/posts/{$postId}/summary")
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

        $this->actingAs($student)->getJson("/student/classes/{$classId}/posts/{$postId}/summary")
            ->assertNotFound();
    }

    public function test_llama_failure_returns_a_friendly_502(): void
    {
        Storage::fake('local');
        [$student, $classId, $postId] = $this->enrolledStudentWithLesson();

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->once()->andThrow(new LlamaApiException('boom'));
        $this->app->instance(LlamaService::class, $llama);

        $res = $this->actingAs($student)->getJson("/student/classes/{$classId}/posts/{$postId}/summary");
        $res->assertStatus(502);
        $this->assertDatabaseCount('lesson_summaries', 0);
    }
}
