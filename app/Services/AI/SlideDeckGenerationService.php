<?php

namespace App\Services\AI;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Exceptions\AI\LlamaApiException;
use App\Services\Documents\LessonContentService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Drafts a slide-deck outline from an uploaded reading. Llama writes the
 * outline as JSON; it is validated here before PresentationBuilderService
 * turns it into a .pptx file.
 *
 * Response time grows with the number of slides written, so longer decks
 * are split into parts of at most SLIDES_PER_PART slides, each covering its
 * own section of the reading, and the parts are generated in parallel.
 */
class SlideDeckGenerationService
{
    public const SLIDE_COUNTS = [8, 12, 16, 20];

    public const SLIDES_PER_PART = 5;

    /** Upper bound on source text sent to Llama in one request. */
    private const MAX_SOURCE_CHARS = 24000;

    private const MIN_SLIDES = 3;

    /** Allowed overshoot beyond the requested slide count (per part, and overall). */
    private const SLIDE_COUNT_TOLERANCE = 4;

    private const MAX_BULLETS = 6;

    private const MAX_TITLE_CHARS = 90;

    private const MAX_BULLET_CHARS = 200;

    private const MAX_NOTES_CHARS = 600;

    public function __construct(
        private LlamaService $llama,
        private PromptService $prompts,
        private LessonContentService $lessonContent,
    ) {
    }

    /**
     * $focus is the class's weak and strong competencies (from BKT mastery)
     * to emphasise or keep brief. Slides that emphasise a weak competency
     * come back with a "focus" key naming it.
     *
     * @param  array{weaknesses: array<int, array{name: string, mastery: float}>, strengths: array<int, array{name: string, mastery: float}>}|null  $focus
     * @return array{title: string, slides: array<int, array{title: string, bullets: array<int, string>, notes: ?string, focus?: string}>}
     *
     * @throws LlamaApiException
     * @throws InvalidAiResponseException
     */
    public function generate(string $sourceName, string $text, int $slideCount, ?array $focus = null): array
    {
        $parts = $this->plan($this->condense($text), $slideCount);
        $count = count($parts);

        $conversations = [];
        foreach ($parts as $i => $part) {
            $conversations[$i] = $this->prompts->slideDeckMessages($sourceName, $part['content'], $part['slides'], $i + 1, $count, $focus);
        }

        // Each part gets one quick attempt on Llama; parts that fail or come
        // back unusable are retried once on the fallback model (DeepSeek).
        $limits = array_map('intval', (array) config('services.routeway.slide_deck', []));

        $results = $this->llama->chatConcurrent(
            $conversations,
            $limits,
            fn (string $raw, int $i) => $this->loggedParse($raw, $i, $parts[$i]['slides']),
        );

        $deck = $this->assemble(array_column($results, 'value'), $slideCount);
        $deck['slides'] = $this->resolveFocus($deck['slides'], $focus['weaknesses'] ?? []);

        return $deck;
    }

    /**
     * Keep a slide's "focus" tag only when it names one of the requested
     * weak competencies (normalised to that competency's exact name), and
     * flag those slides in the speaker notes for the teacher.
     *
     * @param  array<int, array<string, mixed>>  $slides
     * @param  array<int, array{name: string, mastery: float}>  $weaknesses
     * @return array<int, array<string, mixed>>
     */
    private function resolveFocus(array $slides, array $weaknesses): array
    {
        $byKey = [];
        foreach ($weaknesses as $topic) {
            $byKey[Str::lower($topic['name'])] = $topic;
        }

        return array_map(function (array $slide) use ($byKey) {
            $topic = $byKey[Str::lower($slide['focus'] ?? '')] ?? null;
            unset($slide['focus']);

            if ($topic) {
                $slide['focus'] = $topic['name'];
                $slide['notes'] = trim("Class focus: {$topic['name']} (class mastery {$topic['mastery']}%). ".($slide['notes'] ?? ''));
            }

            return $slide;
        }, $slides);
    }

    /**
     * Split the deck into parts of at most SLIDES_PER_PART slides and give
     * each part its own consecutive section of the source (split on sentence
     * boundaries, balanced by length), so parts don't repeat each other. A
     * source with fewer sentences than parts is shared by every part.
     *
     * @return array<int, array{slides: int, content: string}>
     */
    public function plan(string $text, int $slideCount): array
    {
        $count = max(1, (int) ceil($slideCount / self::SLIDES_PER_PART));
        $sections = $this->lessonContent->sections($text, $count);

        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            $parts[] = [
                'slides' => intdiv($slideCount, $count) + ($i < $slideCount % $count ? 1 : 0),
                'content' => $sections[$i] ?? $text,
            ];
        }

        return $parts;
    }

    /**
     * Long readings are sampled evenly across the document (rather than
     * truncated) so later sections still inform the deck.
     */
    public function condense(string $text): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= self::MAX_SOURCE_CHARS) {
            return $text;
        }

        $chunks = $this->lessonContent->chunk($text);
        $keep = max(1, intdiv(self::MAX_SOURCE_CHARS, LessonContentService::CHUNK_CHARS));
        $step = count($chunks) / $keep;

        $sampled = [];
        for ($i = 0; $i < $keep; $i++) {
            $sampled[] = $chunks[(int) floor($i * $step)];
        }

        return implode("\n…\n", $sampled);
    }

    /**
     * Validate a single-part outline into a deck.
     *
     * @return array{title: string, slides: array<int, array{title: string, bullets: array<int, string>, notes: ?string}>}
     *
     * @throws InvalidAiResponseException
     */
    public function parseAndValidate(string $raw, int $slideCount): array
    {
        return $this->assemble([$this->parsePart($raw, $slideCount + self::SLIDE_COUNT_TOLERANCE)], $slideCount);
    }

    /**
     * Valid slides from one part's JSON; the title may be empty.
     *
     * @return array{title: string, slides: array<int, array{title: string, bullets: array<int, string>, notes: ?string}>}
     *
     * @throws InvalidAiResponseException
     */
    public function parsePart(string $raw, int $maxSlides): array
    {
        $decoded = json_decode($raw, true);

        if (! is_array($decoded) && preg_match('/\{.*\}/s', $raw, $matches)) {
            $decoded = json_decode($matches[0], true);
        }

        if (! is_array($decoded) || ! is_array($decoded['slides'] ?? null)) {
            throw new InvalidAiResponseException('AI response was not valid slide deck JSON.');
        }

        $slides = [];

        foreach ($decoded['slides'] as $slide) {
            if (! is_array($slide)) {
                continue;
            }

            $slideTitle = $this->cleanString($slide['title'] ?? null);
            $bullets = array_values(array_filter(array_map(
                fn ($b) => Str::limit($this->cleanString($b), self::MAX_BULLET_CHARS),
                is_array($slide['bullets'] ?? null) ? $slide['bullets'] : [],
            )));

            if ($slideTitle === '' || empty($bullets)) {
                continue;
            }

            $notes = $this->cleanString($slide['notes'] ?? null);
            $focus = $this->cleanString($slide['focus'] ?? null);

            $slides[] = [
                'title' => Str::limit($slideTitle, self::MAX_TITLE_CHARS),
                'bullets' => array_slice($bullets, 0, self::MAX_BULLETS),
                'notes' => $notes === '' ? null : Str::limit($notes, self::MAX_NOTES_CHARS),
            ] + ($focus === '' ? [] : ['focus' => $focus]);
        }

        if (empty($slides)) {
            throw new InvalidAiResponseException('AI slide deck part has no valid slides.');
        }

        return [
            'title' => Str::limit($this->cleanString($decoded['title'] ?? null), self::MAX_TITLE_CHARS),
            'slides' => array_slice($slides, 0, $maxSlides),
        ];
    }

    /**
     * Join parts in order into one deck; the first part's title names it.
     *
     * @param  array<int, array{title: string, slides: array}>  $parts
     *
     * @throws InvalidAiResponseException
     */
    private function assemble(array $parts, int $slideCount): array
    {
        // Drop any "(Part 1)"-style suffix a model adds despite the prompt.
        $title = trim(preg_replace('/[\s:\-–—]*[(\[]?\s*part\s+\d+(\s+of\s+\d+)?\s*[)\]]?$/iu', '', $parts[0]['title'] ?? '') ?? '');

        if ($title === '') {
            throw new InvalidAiResponseException('AI slide deck has no title.');
        }

        // Parts are written independently, so drop a slide whose title
        // repeats an earlier one (ignoring case and punctuation).
        $seen = [];
        $slides = array_values(array_filter(
            array_merge(...array_column($parts, 'slides')),
            function (array $slide) use (&$seen) {
                $key = preg_replace('/[^\p{L}\p{N}]+/u', '', Str::lower($slide['title']));

                if (isset($seen[$key])) {
                    return false;
                }

                return $seen[$key] = true;
            },
        ));

        if (count($slides) < self::MIN_SLIDES) {
            throw new InvalidAiResponseException('AI slide deck has too few valid slides.');
        }

        return [
            'title' => $title,
            'slides' => array_slice($slides, 0, $slideCount + self::SLIDE_COUNT_TOLERANCE),
        ];
    }

    /**
     * Parse callback for chatConcurrent: part 1 must carry the deck title,
     * so a part-1 reply without one is rejected (and retried on the fallback).
     *
     * @throws InvalidAiResponseException
     */
    private function loggedParse(string $raw, int $index, int $slides): array
    {
        try {
            $part = $this->parsePart($raw, $slides + self::SLIDE_COUNT_TOLERANCE);

            if ($index === 0 && $part['title'] === '') {
                throw new InvalidAiResponseException('AI slide deck has no title.');
            }
        } catch (InvalidAiResponseException $e) {
            Log::channel('ai')->error('[slide-deck] AI response failed validation.', [
                'part' => $index + 1,
                'reason' => $e->getMessage(),
                'raw_response' => Str::limit(str_replace(["\r", "\n"], ' ', $raw), 2000),
            ]);

            throw $e;
        }

        return $part;
    }

    private function cleanString(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
    }
}
