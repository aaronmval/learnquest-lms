<?php

namespace App\Services\Documents;

use App\Models\ClassPost;
use App\Models\ClassRoom;
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

    /** Fallback context for a lesson with no stored AI summary yet. */
    private const LESSON_EXCERPT_CHARS = 800;

    private const POST_BODY_CHARS = 400;

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
        $pieces = $this->sentences($text);

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
     * Split text on sentence and line boundaries.
     *
     * @return array<int, string>
     */
    public function sentences(string $text): array
    {
        return preg_split('/(?<=[.!?])\s+|\n+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Split text into $count consecutive sections on sentence boundaries,
     * balanced by length — so parallel AI requests (slide-deck parts, quiz
     * batches) each work from a different part of the material.
     *
     * @return array<int, string>  exactly $count sections, or [] when the text has fewer sentences than that
     */
    public function sections(string $text, int $count): array
    {
        $sentences = $this->sentences($text);

        if ($count <= 1) {
            return [trim($text)];
        }

        if (count($sentences) < $count) {
            return [];
        }

        $target = mb_strlen(implode(' ', $sentences)) / $count;
        $sections = [];
        $current = [];
        $length = 0;

        foreach ($sentences as $i => $sentence) {
            $current[] = $sentence;
            $length += mb_strlen($sentence) + 1;

            $remainingSentences = count($sentences) - $i - 1;
            $remainingSections = $count - count($sections) - 1;

            // Close this section once it reaches its share, while leaving at
            // least one sentence for every section still to fill.
            if ($remainingSections > 0 && ($length >= $target || $remainingSentences === $remainingSections)) {
                $sections[] = implode(' ', $current);
                $current = [];
                $length = 0;
            }
        }

        $sections[] = implode(' ', $current);

        return $sections;
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
     * Prompt-ready lesson list: the stored AI summary when there is one,
     * otherwise the opening of the lesson's PDF text.
     *
     * @param  Collection<int, ClassPost>  $lessonPosts  newest first, with `summary` loaded
     * @return array<int, array{title:string, overview:?string, key_points:array<int, string>, excerpt:?string}>
     */
    public function lessonSummaries(Collection $lessonPosts, int $limit = 10): array
    {
        return $lessonPosts
            ->take($limit)
            ->map(function (ClassPost $post) {
                $excerpt = null;

                if (! $post->summary) {
                    $text = $this->textFor($post);
                    $excerpt = $text !== null ? Str::limit($text, self::LESSON_EXCERPT_CHARS) : null;
                }

                return [
                    'title' => $post->title,
                    'overview' => $post->summary?->overview,
                    'key_points' => $post->summary?->key_points ?? [],
                    'excerpt' => $excerpt,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Newest posts of every type (announcements included) in the given
     * classes, so QuestAI can talk about the class notes, not only lessons.
     *
     * @param  Collection<int, ClassRoom>  $classes
     * @return array<int, array{class:string, type:string, date:string, title:string, body:?string, checklist:array<int, string>}>
     */
    public function recentClassPosts(Collection $classes, int $limit = 10): array
    {
        $labels = $classes->mapWithKeys(fn (ClassRoom $c) => [$c->id => $c->subject ?: $c->name]);

        return ClassPost::whereIn('class_id', $classes->pluck('id'))
            ->latest('id')
            ->take($limit)
            ->get()
            ->map(fn (ClassPost $post) => [
                'class' => (string) ($labels[$post->class_id] ?? ''),
                'type' => $post->type,
                'date' => $post->created_at?->format('M j, Y') ?? '',
                'title' => $post->title,
                'body' => filled($post->body) ? Str::limit(trim($post->body), self::POST_BODY_CHARS) : null,
                'checklist' => array_values(array_filter((array) ($post->checklist ?? []), 'is_string')),
            ])
            ->all();
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
