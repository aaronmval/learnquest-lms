<?php

namespace App\Services\AI;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Models\ClassPost;
use App\Models\LessonSummary;
use App\Services\Documents\PdfTextExtractorService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SummarizationService
{
    /** Character cap for lesson content sent in a single prompt (no chunking/RAG yet). */
    private const MAX_CONTENT_CHARS = 12000;

    private const MAX_KEY_POINTS = 6;

    public function __construct(
        private LlamaService $llama,
        private PromptService $prompts,
        private PdfTextExtractorService $extractor,
    ) {
    }

    public function summarizeLessonPost(ClassPost $post): LessonSummary
    {
        $log = Log::channel('ai');
        $log->info('[summary] Starting lesson summarization.', ['class_post_id' => $post->id]);

        if (! $post->attachment_path) {
            throw new RuntimeException('This lesson has no attachment to summarize.');
        }

        $absolutePath = Storage::disk('local')->path($post->attachment_path);
        $content = $this->extractor->extractText($absolutePath);

        $fullLength = strlen($content);
        $content = mb_substr($content, 0, self::MAX_CONTENT_CHARS);

        if (strlen($content) < $fullLength) {
            $log->warning('[summary] Lesson content truncated before prompting.', [
                'class_post_id' => $post->id,
                'full_chars' => $fullLength,
                'truncated_to' => strlen($content),
            ]);
        }

        $subject = $post->classRoom?->subject ?? $post->classRoom?->name ?? 'Science';

        $messages = $this->prompts->lessonSummaryMessages($subject, $post->title, $content);
        $result = $this->llama->chat($messages);
        $raw = $result['content'];
        $modelUsed = $result['model'];

        try {
            [$overview, $keyPoints] = $this->parseAndValidate($raw);
        } catch (InvalidAiResponseException $e) {
            $log->error('[summary] AI response failed validation — not saved.', [
                'class_post_id' => $post->id,
                'model' => $modelUsed,
                'reason' => $e->getMessage(),
                'raw_response' => Str::limit(str_replace(["\r", "\n"], ' ', $raw), 2000),
            ]);

            throw $e;
        }

        $summary = LessonSummary::updateOrCreate(
            ['class_post_id' => $post->id],
            [
                'overview' => $overview,
                'key_points' => $keyPoints,
                'model' => $modelUsed,
                'generated_at' => now(),
            ],
        );

        $log->info('[summary] Saved lesson summary.', [
            'class_post_id' => $post->id,
            'lesson_summary_id' => $summary->id,
            'model' => $modelUsed,
            'key_point_count' => count($keyPoints),
        ]);

        return $summary;
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function parseAndValidate(string $raw): array
    {
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            // The model may wrap the JSON in prose or markdown fences despite
            // instructions not to — fall back to extracting the outermost object.
            if (preg_match('/\{.*\}/s', $raw, $matches)) {
                $decoded = json_decode($matches[0], true);
            }
        }

        if (! is_array($decoded)) {
            throw new InvalidAiResponseException('AI response was not valid JSON.');
        }

        $overview = $decoded['overview'] ?? null;
        $keyPoints = $decoded['key_points'] ?? null;

        if (! is_string($overview) || trim($overview) === '') {
            throw new InvalidAiResponseException('AI response is missing a valid overview.');
        }

        if (! is_array($keyPoints) || count($keyPoints) === 0) {
            throw new InvalidAiResponseException('AI response is missing valid key points.');
        }

        $keyPoints = array_values(array_filter(array_map(
            fn ($point) => is_string($point) ? trim($point) : null,
            $keyPoints,
        ), fn ($point) => ! empty($point)));

        if (count($keyPoints) === 0) {
            throw new InvalidAiResponseException('AI response is missing valid key points.');
        }

        return [trim($overview), array_slice($keyPoints, 0, self::MAX_KEY_POINTS)];
    }
}
