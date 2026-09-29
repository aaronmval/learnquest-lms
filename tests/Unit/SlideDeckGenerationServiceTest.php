<?php

namespace Tests\Unit;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Services\AI\LlamaService;
use App\Services\AI\PromptService;
use App\Services\AI\SlideDeckGenerationService;
use App\Services\Documents\LessonContentService;
use App\Services\Documents\PdfTextExtractorService;
use Mockery;
use PHPUnit\Framework\TestCase;

class SlideDeckGenerationServiceTest extends TestCase
{
    private SlideDeckGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SlideDeckGenerationService(
            Mockery::mock(LlamaService::class),
            new PromptService,
            new LessonContentService(Mockery::mock(PdfTextExtractorService::class)),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function slide(string $title, array $bullets, $notes = 'Notes'): array
    {
        return ['title' => $title, 'bullets' => $bullets, 'notes' => $notes];
    }

    public function test_accepts_valid_outline_and_extracts_json_from_surrounding_text(): void
    {
        $json = json_encode(['title' => 'Moles', 'slides' => [
            $this->slide('Intro', ['A', 'B']),
            $this->slide('Molar mass', ['C']),
            $this->slide('Ratios', ['D', 'E'], null),
        ]]);

        $deck = $this->service->parseAndValidate("Here you go:\n{$json}\nEnjoy!", 8);

        $this->assertSame('Moles', $deck['title']);
        $this->assertCount(3, $deck['slides']);
        $this->assertNull($deck['slides'][2]['notes']);
    }

    public function test_drops_malformed_slides_and_caps_bullets_and_slide_count(): void
    {
        $slides = [
            $this->slide('', ['No title']),
            $this->slide('No bullets', []),
            $this->slide('Bad bullets', [123, null, '  ']),
            'not a slide',
        ];
        foreach (range(1, 20) as $i) {
            $slides[] = $this->slide("Slide {$i}", ['1', '2', '3', '4', '5', '6', '7', '8']);
        }

        $deck = $this->service->parseAndValidate(json_encode(['title' => 'Deck', 'slides' => $slides]), 8);

        $this->assertCount(12, $deck['slides']); // requested 8 + tolerance 4
        $this->assertSame('Slide 1', $deck['slides'][0]['title']);
        $this->assertCount(6, $deck['slides'][0]['bullets']);
    }

    public function test_drops_repeated_slide_titles_and_part_numbers_in_the_deck_title(): void
    {
        $json = json_encode(['title' => 'Stoichiometry (Part 1)', 'slides' => [
            $this->slide('Percent Yield', ['A']),
            $this->slide('Molar Mass', ['B']),
            $this->slide('percent  yield!', ['C']),
            $this->slide('Limiting Reactant', ['D']),
        ]]);

        $deck = $this->service->parseAndValidate($json, 8);

        $this->assertSame('Stoichiometry', $deck['title']);
        $this->assertSame(['Percent Yield', 'Molar Mass', 'Limiting Reactant'], array_column($deck['slides'], 'title'));
    }

    public function test_rejects_non_json_missing_title_and_too_few_slides(): void
    {
        foreach ([
            'No JSON at all',
            json_encode(['slides' => [$this->slide('A', ['x']), $this->slide('B', ['y']), $this->slide('C', ['z'])]]),
            json_encode(['title' => 'Deck', 'slides' => [$this->slide('A', ['x']), $this->slide('B', ['y'])]]),
            json_encode(['title' => 'Deck', 'slides' => 'three slides']),
        ] as $raw) {
            try {
                $this->service->parseAndValidate($raw, 8);
                $this->fail("Expected rejection of: {$raw}");
            } catch (InvalidAiResponseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_plan_splits_decks_into_parts_with_consecutive_source_sections(): void
    {
        $text = implode(' ', array_map(fn ($i) => str_repeat("Section {$i} sentence. ", 60), range(1, 8)));

        $this->assertSame([4, 4], array_column($this->service->plan($text, 8), 'slides'));
        $this->assertSame([4, 4, 4], array_column($this->service->plan($text, 12), 'slides'));
        $this->assertSame([5, 5, 5, 5], array_column($this->service->plan($text, 20), 'slides'));

        $parts = $this->service->plan($text, 16);
        $this->assertSame([4, 4, 4, 4], array_column($parts, 'slides'));
        $this->assertStringContainsString('Section 1 ', $parts[0]['content']);
        $this->assertStringNotContainsString('Section 8 ', $parts[0]['content']);
        $this->assertStringContainsString('Section 8 ', $parts[3]['content']);

        // A short reading still gets one distinct section per part.
        $short = $this->service->plan('Moles count particles. Molar mass is grams per mole. Limiting reactants run out first. Percent yield compares actual to theoretical.', 16);
        $this->assertSame(
            ['Moles count particles.', 'Molar mass is grams per mole.', 'Limiting reactants run out first.', 'Percent yield compares actual to theoretical.'],
            array_column($short, 'content'),
        );

        // Fewer sentences than parts: every part shares the whole reading.
        $shared = $this->service->plan('Short reading.', 12);
        $this->assertSame(['Short reading.', 'Short reading.', 'Short reading.'], array_column($shared, 'content'));
    }

    public function test_condense_keeps_short_text_and_samples_long_text_across_the_document(): void
    {
        $this->assertSame('Short reading.', $this->service->condense('  Short reading.  '));

        $sentences = array_map(fn ($i) => "Sentence number {$i} about chemistry.", range(1, 3000));
        $condensed = $this->service->condense(implode(' ', $sentences));

        $this->assertLessThanOrEqual(24000 + 200, mb_strlen($condensed));
        $this->assertStringContainsString('Sentence number 1 ', $condensed);
        // Later parts of the document are still represented.
        $this->assertMatchesRegularExpression('/Sentence number 2[5-9]\d\d /', $condensed);
    }
}
