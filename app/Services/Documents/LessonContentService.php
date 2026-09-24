<?php

namespace App\Services\Documents;

use App\Models\ClassPost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lightweight retrieval over uploaded lesson PDFs: extract once, cache the
 * text, split it into chunks, and rank chunks by keyword overlap with a
 * question. Deliberately simple (no vector database) — enough to ground
 * QuestAI answers in the teacher's actual material.
 */
class LessonContentService
{
    public const CHUNK_CHARS = 1200;

    /** Upper bound on lessons scanned per query, newest first. */
    public const MAX_LESSONS = 15;

    private const MIN_TOKEN_LENGTH = 3;

    private const STOPWORDS = [
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'your', 'with', 'this', 'that', 'what',
        'how', 'why', 'who', 'when', 'where', 'which', 'can', 'does', 'did', 'was', 'were', 'has',
        'have', 'had', 'from', 'into', 'about', 'explain', 'give', 'tell', 'please', 'me', 'our',
        'they', 'them', 'their', 'there', 'its', 'also', 'will', 'would', 'could', 'should', 'some',
        'any', 'all', 'more', 'most', 'other', 'than', 'then', 'just', 'like', 'very', 'simply',
        'topic', 'question', 'questions', 'practice', 'help', 'understand', 'mean', 'means',
    ];

    public function __construct(private PdfTextExtractorService $extractor)
    {
    }

    /**
     * Extracted text of a lesson's PDF attachment, cached per file. Returns
     * null when the lesson has no PDF or its text can't be extracted.
     */
    public function textFor(ClassPost $post): ?string
    {
        if (! $this->hasPdf($post)) {
            return null;
        }

        $key = "lesson-text:{$post->id}:".md5($post->attachment_path);

        // Failures cache as '' so an unreadable (e.g. scanned) PDF isn't
        // re-parsed on every question; a replaced file gets a new key.
        $text = Cache::rememberForever($key, function () use ($post) {
            try {
                return $this->extractor->extractText(Storage::disk('local')->path($post->attachment_path));
            } catch (Throwable $e) {
                Log::channel('ai')->warning('[lesson-content] Could not extract lesson text.', [
                    'class_post_id' => $post->id,
                    'error' => $e->getMessage(),
                ]);

                return '';
            }
        });

        return $text === '' ? null : $text;
    }

    /**
     * Split text into ~CHUNK_CHARS pieces on line/sentence boundaries.
     *
     * @return array<int, string>
     */
    public function chunk(string $text): array
    {
        $pieces = preg_split('/(?<=[.!?])\s+|\n+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $chunks = [];
        $current = '';

        foreach ($pieces as $piece) {
            $piece = trim($piece);

            // A single oversized piece (no sentence breaks) is hard-split.
            foreach (mb_str_split($piece, self::CHUNK_CHARS) as $part) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($part) + 1 > self::CHUNK_CHARS) {
                    $chunks[] = $current;
                    $current = '';
                }

                $current = $current === '' ? $part : "{$current} {$part}";
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * The lesson chunks most relevant to $query, best first.
     *
     * @param  Collection<int, ClassPost>  $posts
     * @return array<int, array{post_id:int, post_title:string, text:string}>
     */
    public function retrieve(Collection $posts, string $query, int $limit = 4): array
    {
        $terms = $this->tokenize($query);

        if (empty($terms)) {
            return [];
        }

        $scored = [];

        $lessons = $posts
            ->filter(fn (ClassPost $post) => $this->hasPdf($post))
            ->sortByDesc('id')
            ->take(self::MAX_LESSONS);

        foreach ($lessons as $post) {
            $text = $this->textFor($post);

            if ($text === null) {
                continue;
            }

            foreach ($this->chunk($text) as $chunk) {
                $score = $this->score($chunk, $terms);

                if ($score > 0) {
                    $scored[] = ['post_id' => $post->id, 'post_title' => $post->title, 'text' => $chunk, 'score' => $score];
                }
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(
            fn ($c) => ['post_id' => $c['post_id'], 'post_title' => $c['post_title'], 'text' => $c['text']],
            array_slice($scored, 0, $limit),
        );
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', Str::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $tokens,
            fn ($t) => mb_strlen($t) >= self::MIN_TOKEN_LENGTH && ! in_array($t, self::STOPWORDS, true),
        )));
    }

    /**
     * Term-frequency score; each distinct matched term also earns a bonus
     * so a chunk covering more of the question outranks one repeating a
     * single word.
     */
    private function score(string $chunk, array $terms): int
    {
        $haystack = Str::lower($chunk);
        $score = 0;

        foreach ($terms as $term) {
            $count = substr_count($haystack, $term);

            if ($count > 0) {
                $score += $count + 3;
            }
        }

        return $score;
    }

    private function hasPdf(ClassPost $post): bool
    {
        return $post->type === 'lesson'
            && $post->attachment_path
            && Str::endsWith(Str::lower($post->attachment_name ?: $post->attachment_path), '.pdf');
    }
}
