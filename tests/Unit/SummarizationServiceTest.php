<?php

namespace Tests\Unit;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\LessonSummary;
use App\Models\User;
use App\Services\AI\LlamaService;
use App\Services\AI\PromptService;
use App\Services\AI\SummarizationService;
use App\Services\Documents\PdfTextExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SummarizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeLessonPost(): ClassPost
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $class = ClassRoom::create([
            'professor_id' => $professor->id,
            'name' => 'Homeroom',
            'subject' => 'Chemistry',
        ]);

        return ClassPost::create([
            'class_id' => $class->id,
            'author_id' => $professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Stoichiometry',
            'attachment_path' => 'class-posts/1/lesson.pdf',
            'attachment_name' => 'lesson.pdf',
        ]);
    }

    private function serviceReturning(string $llamaResponse): SummarizationService
    {
        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->once()
            ->andReturn(['content' => $llamaResponse, 'model' => 'llama-3.3-70b-instruct']);

        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldReceive('extractText')->once()
            ->andReturn('Some lesson content about stoichiometry.');

        return new SummarizationService($llama, new PromptService, $extractor);
    }

    public function test_valid_json_response_is_saved(): void
    {
        $post = $this->makeLessonPost();
        $service = $this->serviceReturning(json_encode([
            'overview' => 'This lesson covers stoichiometry basics.',
            'key_points' => ['Balance equations', 'Use mole ratios', 'Convert units'],
        ]));

        $summary = $service->summarizeLessonPost($post);

        $this->assertInstanceOf(LessonSummary::class, $summary);
        $this->assertSame('This lesson covers stoichiometry basics.', $summary->overview);
        $this->assertCount(3, $summary->key_points);
        $this->assertSame('llama-3.3-70b-instruct', $summary->model);
        $this->assertDatabaseCount('lesson_summaries', 1);
    }

    public function test_response_wrapped_in_prose_is_still_parsed(): void
    {
        $post = $this->makeLessonPost();
        $service = $this->serviceReturning(
            'Sure! Here you go: {"overview": "Recap.", "key_points": ["Point one"]}',
        );

        $summary = $service->summarizeLessonPost($post);

        $this->assertSame('Recap.', $summary->overview);
    }

    public function test_missing_overview_is_rejected(): void
    {
        $post = $this->makeLessonPost();
        $service = $this->serviceReturning(json_encode(['key_points' => ['Point one']]));

        $this->expectException(InvalidAiResponseException::class);
        $service->summarizeLessonPost($post);
    }

    public function test_non_array_key_points_is_rejected(): void
    {
        $post = $this->makeLessonPost();
        $service = $this->serviceReturning(json_encode([
            'overview' => 'Recap.',
            'key_points' => 'not an array',
        ]));

        $this->expectException(InvalidAiResponseException::class);
        $service->summarizeLessonPost($post);
    }

    public function test_non_json_response_is_rejected(): void
    {
        $post = $this->makeLessonPost();
        $service = $this->serviceReturning('not json at all');

        $this->expectException(InvalidAiResponseException::class);
        $service->summarizeLessonPost($post);
    }
}
