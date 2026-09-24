<?php

namespace Tests\Unit;

use App\Models\ClassPost;
use App\Services\Documents\LessonContentService;
use App\Services\Documents\PdfTextExtractorService;
use Mockery;
use Tests\TestCase;

class LessonContentServiceTest extends TestCase
{
    private function lessonPost(int $id, string $title): ClassPost
    {
        $post = new ClassPost([
            'type' => 'lesson',
            'title' => $title,
            'attachment_path' => "class-posts/{$id}.pdf",
            'attachment_name' => "{$id}.pdf",
        ]);
        $post->id = $id;

        return $post;
    }

    public function test_chunks_stay_within_size_and_keep_all_text(): void
    {
        $service = new LessonContentService(Mockery::mock(PdfTextExtractorService::class));
        $sentence = str_repeat('word ', 40).'end.';
        $text = implode(' ', array_fill(0, 30, $sentence));

        $chunks = $service->chunk($text);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(LessonContentService::CHUNK_CHARS, mb_strlen($chunk));
        }
        $this->assertSame(30, substr_count(implode(' ', $chunks), 'end.'));
    }

    public function test_oversized_piece_without_breaks_is_hard_split(): void
    {
        $service = new LessonContentService(Mockery::mock(PdfTextExtractorService::class));

        $chunks = $service->chunk(str_repeat('a', LessonContentService::CHUNK_CHARS * 2 + 10));

        $this->assertCount(3, $chunks);
    }

    public function test_retrieve_ranks_matching_lesson_first_and_caches_extraction(): void
    {
        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldReceive('extractText')->once()
            ->with(Mockery::on(fn ($path) => str_contains($path, '1.pdf')))
            ->andReturn('Photosynthesis converts light energy into chemical energy in chloroplasts.');
        $extractor->shouldReceive('extractText')->once()
            ->with(Mockery::on(fn ($path) => str_contains($path, '2.pdf')))
            ->andReturn('Stoichiometry uses the mole ratio from a balanced chemical equation.');

        $service = new LessonContentService($extractor);
        $posts = collect([$this->lessonPost(1, 'Photosynthesis'), $this->lessonPost(2, 'Stoichiometry')]);

        $results = $service->retrieve($posts, 'How do I use the mole ratio?');

        $this->assertSame(2, $results[0]['post_id']);
        $this->assertSame('Stoichiometry', $results[0]['post_title']);
        $this->assertCount(1, $results);

        // Second query hits the cache — extractor expectations are once() each.
        $service->retrieve($posts, 'chloroplasts light energy');
    }

    public function test_non_pdf_and_empty_queries_return_nothing(): void
    {
        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldNotReceive('extractText');
        $service = new LessonContentService($extractor);

        $pptPost = $this->lessonPost(3, 'Slides');
        $pptPost->attachment_name = 'slides.pptx';
        $pptPost->attachment_path = 'class-posts/slides.pptx';

        $this->assertSame([], $service->retrieve(collect([$pptPost]), 'mole ratio'));
        $this->assertSame([], $service->retrieve(collect([$this->lessonPost(4, 'X')]), 'what is the'));
    }
}
