<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AI\LlamaService;
use App\Services\Documents\PdfTextExtractorService;
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
        $this->assertTrue($zip->open($download->getFile()->getPathname()) === true);
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

    public function test_students_cannot_generate_decks(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student)->post('/professor/questai/decks', [], ['Accept' => 'application/json'])
            ->assertRedirect('/login');
    }
}
