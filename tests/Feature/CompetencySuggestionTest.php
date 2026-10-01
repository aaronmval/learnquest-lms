<?php

namespace Tests\Feature;

use App\Exceptions\AI\LlamaApiException;
use App\Models\User;
use App\Services\AI\LlamaService;
use App\Services\Documents\PdfTextExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CompetencySuggestionTest extends TestCase
{
    use RefreshDatabase;

    private User $professor;

    private int $subjectId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->professor = User::factory()->create(['role' => 'professor']);
        $this->subjectId = $this->actingAs($this->professor)
            ->postJson('/professor/subjects', ['name' => 'General Chemistry 2'])->json('id');
    }

    private function uploadModule(string $title): void
    {
        $this->actingAs($this->professor)->post("/professor/subjects/{$this->subjectId}/modules", [
            'title' => $title,
            'attachment' => UploadedFile::fake()->create('module.pdf', 200, 'application/pdf'),
        ])->assertCreated();
    }

    private function mockExtractor(string|RuntimeException $result): void
    {
        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $expectation = $extractor->shouldReceive('extractText');
        $result instanceof RuntimeException ? $expectation->andThrow($result) : $expectation->andReturn($result);
        $this->app->instance(PdfTextExtractorService::class, $extractor);
    }

    private function reply(array $competencies): array
    {
        return ['content' => json_encode(['competencies' => $competencies]), 'model' => 'llama-3.3-70b-instruct'];
    }

    private function suggest()
    {
        return $this->actingAs($this->professor)->postJson("/professor/subjects/{$this->subjectId}/competencies/suggest");
    }

    public function test_suggestions_are_grounded_in_module_text_validated_and_not_saved(): void
    {
        $this->uploadModule('SLM 2');
        $this->uploadModule('SLM 1');
        $this->actingAs($this->professor)
            ->postJson("/professor/subjects/{$this->subjectId}/competencies", ['name' => 'Intermolecular Forces'])
            ->assertCreated();
        $this->mockExtractor('Dipole-dipole forces and hydrogen bonding explain boiling points.');

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->once()->withArgs(function (array $messages, array $options) {
            $prompt = implode("\n", array_column($messages, 'content'));

            return $options['timeout'] === 30
                && $options['max_retries'] === 1
                && str_contains($prompt, 'Subject: General Chemistry 2')
                && str_contains($prompt, 'hydrogen bonding')
                && str_contains($prompt, 'Existing competencies (do not repeat): Intermolecular Forces')
                // Modules are sent in natural title order.
                && strpos($prompt, '### Module: SLM 1') < strpos($prompt, '### Module: SLM 2');
        })->andReturn($this->reply([
            ['name' => 'Properties of Liquids', 'description' => 'Explain surface tension and viscosity.', 'modules' => ['SLM 1', 'Made Up Module']],
            ['name' => 'intermolecular  forces', 'description' => 'Duplicate of an existing competency.'],
            ['name' => 'Properties of Liquids', 'description' => 'Repeated in the same reply.'],
            ['name' => '', 'description' => 'No name.'],
            ['name' => 'Phase Changes', 'modules' => 'SLM 2'],
        ]));
        $this->app->instance(LlamaService::class, $llama);

        $res = $this->suggest()->assertOk();
        $res->assertJsonCount(2, 'suggestions');
        $res->assertJsonPath('suggestions.0.name', 'Properties of Liquids');
        $res->assertJsonPath('suggestions.0.modules', ['SLM 1']);
        $res->assertJsonPath('suggestions.1.name', 'Phase Changes');
        $res->assertJsonPath('suggestions.1.description', null);
        $res->assertJsonPath('suggestions.1.modules', ['SLM 2']);
        $res->assertJsonPath('modules_total', 2);
        $res->assertJsonPath('modules_read', 2);

        // Suggestions are only proposed — the professor adds them explicitly.
        $this->assertDatabaseCount('competencies', 1);
    }

    public function test_invalid_primary_reply_is_retried_on_the_fallback_model(): void
    {
        $this->uploadModule('SLM 1');
        $this->mockExtractor('Colligative properties of solutions.');

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->once()
            ->withArgs(fn (array $messages, array $options) => empty($options['fallback_only']))
            ->andReturn(['content' => 'Sure! Here are some ideas...', 'model' => 'llama-3.3-70b-instruct']);
        $llama->shouldReceive('chat')->once()
            ->withArgs(fn (array $messages, array $options) => ($options['fallback_only'] ?? false) === true)
            ->andReturn(['content' => json_encode(['competencies' => [['name' => 'Colligative Properties']]]), 'model' => 'deepseek-v4-flash']);
        $this->app->instance(LlamaService::class, $llama);

        $this->suggest()->assertOk()
            ->assertJsonPath('suggestions.0.name', 'Colligative Properties')
            ->assertJsonPath('model', 'deepseek-v4-flash');
    }

    public function test_subject_without_modules_is_rejected_without_calling_ai(): void
    {
        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldNotReceive('chat');
        $this->app->instance(LlamaService::class, $llama);

        $this->suggest()->assertStatus(422)->assertJsonStructure(['message']);
    }

    public function test_unreadable_modules_are_rejected_without_calling_ai(): void
    {
        $this->uploadModule('SLM 1');
        $this->mockExtractor(new RuntimeException('No readable text could be extracted from this PDF.'));

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldNotReceive('chat');
        $this->app->instance(LlamaService::class, $llama);

        $this->suggest()->assertStatus(422);
    }

    public function test_ai_outage_returns_a_friendly_error(): void
    {
        $this->uploadModule('SLM 1');
        $this->mockExtractor('Chemical kinetics and reaction rates.');

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->andThrow(new LlamaApiException('AI service unavailable.'));
        $this->app->instance(LlamaService::class, $llama);

        $this->suggest()->assertStatus(503)
            ->assertJsonMissing(['message' => 'AI service unavailable.']);
    }

    public function test_only_subject_managers_can_request_suggestions(): void
    {
        $other = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($other)->postJson("/professor/subjects/{$this->subjectId}/competencies/suggest")
            ->assertNotFound();

        $status = $this->actingAs($student)
            ->postJson("/professor/subjects/{$this->subjectId}/competencies/suggest")->status();
        $this->assertNotSame(200, $status);
    }
}
