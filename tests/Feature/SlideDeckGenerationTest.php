<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Subject;
use App\Models\User;
use App\Services\AI\LlamaService;
use App\Services\Documents\PdfTextExtractorService;
use App\Services\Learning\ProfessorAnalyticsService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Runs the real LlamaService against a faked Routeway HTTP layer, so the
 * parallel part requests and the DeepSeek fallback are exercised end to end.
 */
class SlideDeckGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $professor;

    /** Requests sent to the faked Routeway API, in order. */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'services.routeway.api_key' => 'test-key',
            'services.routeway.model' => 'llama-3.3-70b-instruct',
            'services.routeway.fallback_model' => 'deepseek-v4-flash',
        ]);
        $this->professor = User::factory()->create(['role' => 'professor']);
    }

    private function mockExtractor(?string $text = 'Stoichiometry uses mole ratios from balanced equations.'): void
    {
        $extractor = Mockery::mock(PdfTextExtractorService::class);

        if ($text === null) {
            $extractor->shouldReceive('extractText')->andThrow(new RuntimeException('No readable text could be extracted from this PDF.'));
        } else {
            $extractor->shouldReceive('extractText')->andReturn($text);
        }

        $this->app->instance(PdfTextExtractorService::class, $extractor);
    }

    /** Queue Routeway responses, consumed in the order requests are sent. */
    private function fakeRouteway(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        $this->app->instance(LlamaService::class, new LlamaService(
            new Client(['handler' => $stack, 'base_uri' => 'https://api.routeway.ai/v1/']),
        ));
    }

    private function reply(string $content): Response
    {
        return new Response(200, [], json_encode(['choices' => [['message' => ['content' => $content]]]]));
    }

    private function part(int $part, int $slides, string $title = ''): Response
    {
        return $this->reply(json_encode([
            'title' => $title,
            'slides' => array_map(fn ($i) => [
                'title' => "Part {$part} slide {$i}",
                'bullets' => ["Part {$part} point {$i}a", "Part {$part} point {$i}b"],
                'notes' => "Notes {$i}",
            ], range(1, $slides)),
        ]));
    }

    /** @return array<int, array{model: string, system: string, timeout: mixed, max_tokens: mixed}> */
    private function sentRequests(): array
    {
        return array_map(function ($entry) {
            $body = json_decode((string) $entry['request']->getBody(), true);

            return [
                'model' => $body['model'],
                'system' => $body['messages'][0]['content'],
                'timeout' => $entry['options']['timeout'] ?? null,
                'max_tokens' => $body['max_tokens'] ?? null,
            ];
        }, $this->sent);
    }

    private function upload(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->professor)->post('/professor/questai/decks', $overrides + [
            'file' => UploadedFile::fake()->create('Mole Concept.pdf', 200, 'application/pdf'),
            'slide_count' => 8,
            'theme' => 'emerald',
        ], ['Accept' => 'application/json']);
    }

    /** Save a streamed download to a temp file so it can be opened as a zip. */
    private function downloadedFile(TestResponse $download): string
    {
        $path = tempnam(sys_get_temp_dir(), 'deck');
        file_put_contents($path, $download->streamedContent());

        return $path;
    }

    public function test_generates_a_downloadable_pptx_from_parallel_parts(): void
    {
        $this->mockExtractor();
        $this->fakeRouteway([$this->part(1, 4, 'Stoichiometry'), $this->part(2, 4)]);

        $res = $this->upload()->assertCreated();
        $res->assertJsonPath('title', 'Stoichiometry');
        $res->assertJsonPath('slide_count', 8);
        $res->assertJsonPath('theme', 'emerald');

        // 8 slides → two parts of 4, both sent to Llama with the short limits.
        $sent = $this->sentRequests();
        $this->assertCount(2, $sent);
        $this->assertSame(['llama-3.3-70b-instruct', 'llama-3.3-70b-instruct'], array_column($sent, 'model'));
        $this->assertStringContainsString('part 1 of 2', $sent[0]['system']);
        $this->assertStringContainsString('Produce exactly 4 slides', $sent[0]['system']);
        $this->assertStringContainsString('part 2 of 2 (the final part)', $sent[1]['system']);
        $this->assertSame(35, $sent[0]['timeout']);
        $this->assertSame(2500, $sent[0]['max_tokens']);

        $download = $this->actingAs($this->professor)->get($res->json('download_url'));
        $download->assertOk();
        $this->assertStringContainsString('mole-concept-slides.pptx', $download->headers->get('content-disposition'));

        // Title slide + 8 content slides, parts kept in order, emerald theme.
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->downloadedFile($download)) === true);
        $this->assertNotFalse($zip->locateName('ppt/slides/slide9.xml'));
        $this->assertStringContainsString('047857', $zip->getFromName('ppt/slides/slide1.xml'));
        $this->assertStringContainsString('Part 1 point 1a', $zip->getFromName('ppt/slides/slide2.xml'));
        $this->assertStringContainsString('Part 2 point 1a', $zip->getFromName('ppt/slides/slide6.xml'));
        $zip->close();
    }

    public function test_twenty_slides_are_split_into_four_parts_sent_together(): void
    {
        $this->mockExtractor(str_repeat('Moles, molar mass and mole ratios in balanced equations. ', 200));
        $this->fakeRouteway([$this->part(1, 5, 'Stoichiometry'), $this->part(2, 5), $this->part(3, 5), $this->part(4, 5)]);

        $this->upload(['slide_count' => 20])->assertCreated()->assertJsonPath('slide_count', 20);

        $sent = $this->sentRequests();
        $this->assertCount(4, $sent);
        $this->assertStringContainsString('part 3 of 4', $sent[2]['system']);
        $this->assertStringContainsString('Produce exactly 5 slides', $sent[3]['system']);
    }

    public function test_failed_or_invalid_llama_parts_are_retried_on_deepseek(): void
    {
        $this->mockExtractor();
        $this->fakeRouteway([
            new Response(502, [], 'Bad Gateway'),       // part 1 on Llama: unreachable
            $this->reply('Sure! Here are slides.'),     // part 2 on Llama: not JSON
            $this->part(1, 4, 'Stoichiometry'),          // part 1 on DeepSeek
            $this->part(2, 4),                           // part 2 on DeepSeek
        ]);

        $this->upload()->assertCreated()->assertJsonPath('slide_count', 8);

        $sent = $this->sentRequests();
        $this->assertSame(
            ['llama-3.3-70b-instruct', 'llama-3.3-70b-instruct', 'deepseek-v4-flash', 'deepseek-v4-flash'],
            array_column($sent, 'model'),
        );
        $this->assertSame(60, $sent[2]['timeout']);
    }

    public function test_only_the_failed_part_is_retried(): void
    {
        $this->mockExtractor();
        $this->fakeRouteway([
            $this->part(1, 4, ''),                       // part 1 without the deck title: rejected
            $this->part(2, 4),
            $this->part(1, 4, 'Stoichiometry'),
        ]);

        $this->upload()->assertCreated()->assertJsonPath('title', 'Stoichiometry');
        $this->assertSame('deepseek-v4-flash', $this->sentRequests()[2]['model']);
        $this->assertCount(3, $this->sent);
    }

    public function test_nothing_is_saved_when_both_models_fail(): void
    {
        $this->mockExtractor();
        $this->fakeRouteway([
            $this->reply('not json'), $this->part(2, 4),
            $this->reply('still not json'),
        ]);

        $this->upload()->assertStatus(502);
        $this->assertEmpty(Storage::disk('local')->allFiles('generated-decks'));
    }

    public function test_other_professors_cannot_download(): void
    {
        $this->mockExtractor();
        $this->fakeRouteway([$this->part(1, 4, 'Stoichiometry'), $this->part(2, 4)]);

        $url = $this->upload()->assertCreated()->json('download_url');

        $other = User::factory()->create(['role' => 'professor']);
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->actingAs($this->professor)->get('/professor/questai/decks/not-a-uuid/download')->assertNotFound();
    }

    public function test_rejects_non_pdf_and_invalid_options(): void
    {
        $this->fakeRouteway([]);

        $this->upload(['file' => UploadedFile::fake()->create('notes.docx', 10)])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload(['slide_count' => 50, 'theme' => 'neon'])
            ->assertStatus(422)->assertJsonValidationErrors(['slide_count', 'theme']);
        $this->assertEmpty($this->sent);
    }

    public function test_unreadable_pdf_is_reported(): void
    {
        $this->mockExtractor(null);

        $this->upload()->assertStatus(422)
            ->assertJsonPath('message', 'No readable text could be extracted from this PDF. Try a PDF with selectable text.');
    }

    private function moduleFor(User $owner, ?string $filePath = 'subject-modules/1/mole.pdf'): Module
    {
        $subject = Subject::create(['owner_id' => $owner->id, 'name' => 'Chemistry']);

        if ($filePath) {
            Storage::disk('local')->put($filePath, 'pdf-bytes');
        }

        return Module::create([
            'subject_id' => $subject->id,
            'uploaded_by' => $owner->id,
            'title' => 'Mole Concept',
            'quarter' => '1st Quarter',
            'file_path' => $filePath ?? 'subject-modules/1/gone.pdf',
            'file_name' => 'Mole Concept Module.pdf',
            'file_size' => 9,
        ]);
    }

    private function generateFromModule(Module $module): TestResponse
    {
        return $this->actingAs($this->professor)->post('/professor/questai/decks', [
            'module_id' => $module->id,
            'slide_count' => 8,
            'theme' => 'emerald',
        ], ['Accept' => 'application/json']);
    }

    public function test_generates_a_deck_from_an_existing_module(): void
    {
        $this->mockExtractor();
        $this->fakeRouteway([$this->part(1, 4, 'Stoichiometry'), $this->part(2, 4)]);

        $res = $this->generateFromModule($this->moduleFor($this->professor))->assertCreated();
        $res->assertJsonPath('slide_count', 8);

        $download = $this->actingAs($this->professor)->get($res->json('download_url'))->assertOk();
        $this->assertStringContainsString(
            'mole-concept-module-slides.pptx',
            $download->headers->get('content-disposition'),
        );
    }

    public function test_cannot_generate_from_another_professors_module(): void
    {
        $this->fakeRouteway([]);
        $other = User::factory()->create(['role' => 'professor']);

        $this->generateFromModule($this->moduleFor($other))->assertNotFound();
        $this->assertEmpty($this->sent);
    }

    public function test_module_with_a_missing_file_is_reported(): void
    {
        $this->fakeRouteway([]);

        $this->generateFromModule($this->moduleFor($this->professor, null))
            ->assertStatus(422)
            ->assertJsonPath('message', "This module's PDF could not be found. Re-upload it on the Modules page and try again.");
        $this->assertEmpty($this->sent);
    }

    public function test_requires_a_file_or_a_module(): void
    {
        $this->fakeRouteway([]);

        $this->actingAs($this->professor)->post('/professor/questai/decks', [
            'slide_count' => 8,
            'theme' => 'emerald',
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    /** Fake the class's BKT overview the targeted focus is read from. */
    private function mockClassMastery(array $competencies): void
    {
        $analytics = Mockery::mock(ProfessorAnalyticsService::class);
        $analytics->shouldReceive('dashboard')->andReturn([
            'sections' => [['id' => 7, 'label' => 'Chemistry — STEM A', 'subject_id' => 1]],
            'competencies' => $competencies,
        ]);

        $this->app->instance(ProfessorAnalyticsService::class, $analytics);
    }

    private function competencyRow(string $name, float $mastery, string $level, int $assessed = 5): array
    {
        return ['id' => crc32($name), 'name' => $name, 'mastery' => $mastery, 'level' => $level, 'students' => 5, 'assessed_students' => $assessed, 'low_students' => 0];
    }

    /** A 4-slide part whose first $tagged slides are tagged with a focus competency. */
    private function focusedPart(int $part, string $title, string $focus, int $tagged): Response
    {
        return $this->reply(json_encode([
            'title' => $title,
            'slides' => array_map(fn ($i) => [
                'title' => "Part {$part} slide {$i}",
                'bullets' => ["Point {$i}a", "Point {$i}b"],
                'notes' => "Notes {$i}",
            ] + ($i <= $tagged ? ['focus' => $focus] : []), range(1, 4)),
        ]));
    }

    public function test_targeted_focus_is_sent_to_llama_and_reported_as_applied(): void
    {
        $this->mockExtractor();
        $this->mockClassMastery([
            $this->competencyRow('Mole Ratios', 35.0, 'low'),
            $this->competencyRow('Molar Mass', 88.0, 'high'),
            $this->competencyRow('Limiting Reagents', 20.0, 'low', assessed: 0),
        ]);
        // The model tags two slides correctly (ignoring case) and invents a third tag.
        $this->fakeRouteway([
            $this->focusedPart(1, 'Stoichiometry', 'mole ratios', 2),
            $this->focusedPart(2, '', 'Something Else', 1),
        ]);

        $res = $this->upload(['focus_class_id' => 7])->assertCreated();
        $res->assertJsonPath('focus.status', 'applied');
        $res->assertJsonPath('focus.topics', [['name' => 'Mole Ratios', 'mastery' => 35, 'slides' => 2]]);
        $res->assertJsonPath('focus.message', 'Targeted focus applied for Chemistry — STEM A: Mole Ratios (2 slides).');

        // BKT mastery is passed as context; unassessed competencies are left out.
        $system = $this->sentRequests()[0]['system'];
        $this->assertStringContainsString('CLASS FOCUS', $system);
        $this->assertStringContainsString('The class is weakest in: Mole Ratios (35% class mastery).', $system);
        $this->assertStringContainsString('high mastery in: Molar Mass (88% class mastery).', $system);
        $this->assertStringNotContainsString('Limiting Reagents', $system);

        // Focused slides are flagged in the speaker notes of the .pptx.
        $download = $this->actingAs($this->professor)->get($res->json('download_url'))->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->downloadedFile($download)) === true);
        $this->assertStringContainsString('Class focus: Mole Ratios', $zip->getFromName('ppt/notesSlides/notesSlide2.xml'));
        $zip->close();
    }

    public function test_focus_is_reported_as_not_applied_when_the_reading_does_not_cover_it(): void
    {
        $this->mockExtractor();
        $this->mockClassMastery([$this->competencyRow('Mole Ratios', 35.0, 'low')]);
        $this->fakeRouteway([$this->part(1, 4, 'Cell Biology'), $this->part(2, 4)]);

        $res = $this->upload(['focus_class_id' => 7])->assertCreated();
        $res->assertJsonPath('focus.status', 'not_covered');
        $res->assertJsonPath('focus.topics.0.slides', 0);
    }

    public function test_focus_is_skipped_without_quiz_data_or_when_mastery_is_high(): void
    {
        $this->mockExtractor();

        $this->mockClassMastery([$this->competencyRow('Mole Ratios', 30.0, 'low', assessed: 0)]);
        $this->fakeRouteway([$this->part(1, 4, 'Stoichiometry'), $this->part(2, 4)]);
        $this->upload(['focus_class_id' => 7])->assertCreated()->assertJsonPath('focus.status', 'no_data');
        $this->assertStringNotContainsString('CLASS FOCUS', $this->sentRequests()[0]['system']);

        $this->mockClassMastery([$this->competencyRow('Molar Mass', 88.0, 'high')]);
        $this->fakeRouteway([$this->part(1, 4, 'Stoichiometry'), $this->part(2, 4)]);
        $this->upload(['focus_class_id' => 7])->assertCreated()->assertJsonPath('focus.status', 'not_needed');
    }

    public function test_no_focus_is_reported_when_none_is_requested(): void
    {
        $this->mockExtractor();
        $this->fakeRouteway([$this->part(1, 4, 'Stoichiometry'), $this->part(2, 4)]);

        $this->upload()->assertCreated()->assertJsonPath('focus', null);
    }

    public function test_cannot_focus_on_a_class_the_professor_does_not_manage(): void
    {
        $this->mockExtractor();
        $this->fakeRouteway([]);

        $this->upload(['focus_class_id' => 999])->assertNotFound();
        $this->assertEmpty($this->sent);
    }

    public function test_students_cannot_generate_decks(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student)->post('/professor/questai/decks', [], ['Accept' => 'application/json'])
            ->assertRedirect('/login');
    }
}
