<?php

namespace Tests\Unit;

use App\Exceptions\AI\InvalidAiResponseException;
use App\Models\ClassPost;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\AI\LlamaService;
use App\Services\AI\PromptService;
use App\Services\AI\QuizGenerationService;
use App\Services\Documents\PdfTextExtractorService;
use App\Services\Quiz\QuizFeedbackService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class QuizGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeLessonPostWithCompetencies(int $competencyCount = 2): array
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $subject = $professor->ownedSubjects()->create(['name' => 'Chemistry']);
        $class = $subject->sections()->create([
            'professor_id' => $professor->id,
            'name' => 'STEM A',
        ]);

        $competencies = collect(range(1, $competencyCount))->map(
            fn ($i) => $subject->competencies()->create(['name' => "Competency {$i}"])
        );

        $post = ClassPost::create([
            'class_id' => $class->id,
            'author_id' => $professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Atomic Structure',
            'attachment_path' => 'class-posts/1/lesson.pdf',
            'attachment_name' => 'lesson.pdf',
        ]);

        return [$post, $competencies];
    }

    private function serviceReturning(string $llamaResponse): QuizGenerationService
    {
        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->once()
            ->andReturn(['content' => $llamaResponse, 'model' => 'llama-3.3-70b-instruct']);

        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldReceive('extractText')->once()
            ->andReturn('Some lesson content about atomic structure.');

        return new QuizGenerationService($llama, new PromptService, $extractor, new QuizFeedbackService);
    }

    private function questionPayload(int $competencyId, array $overrides = []): array
    {
        return array_merge([
            'question' => 'What is the charge of an electron?',
            'choices' => ['Positive', 'Negative', 'Neutral', 'Variable'],
            'correct_answer' => 'Negative',
            'explanation' => 'Electrons carry a negative charge.',
            'competency_id' => $competencyId,
            'difficulty' => 'easy',
        ], $overrides);
    }

    public function test_valid_batch_is_saved_in_full(): void
    {
        [$post, $competencies] = $this->makeLessonPostWithCompetencies();
        $competencyId = $competencies->first()->id;

        $service = $this->serviceReturning(json_encode([
            'questions' => [
                $this->questionPayload($competencyId),
                $this->questionPayload($competencyId, ['question' => 'Q2', 'correct_answer' => 'Positive']),
            ],
        ]));

        $quiz = $service->generateForPost($post);

        $this->assertInstanceOf(Quiz::class, $quiz);
        $this->assertCount(2, $quiz->questions);
        $this->assertDatabaseCount('quiz_questions', 2);
        $this->assertSame('llama-3.3-70b-instruct', $quiz->model);
    }

    public function test_question_with_non_matching_correct_answer_is_dropped(): void
    {
        [$post, $competencies] = $this->makeLessonPostWithCompetencies();
        $competencyId = $competencies->first()->id;

        $service = $this->serviceReturning(json_encode([
            'questions' => [
                $this->questionPayload($competencyId, ['correct_answer' => 'Not one of the choices']),
                $this->questionPayload($competencyId, ['question' => 'Q2']),
            ],
        ]));

        $quiz = $service->generateForPost($post);

        $this->assertCount(1, $quiz->questions);
        $this->assertSame('Q2', $quiz->questions->first()->question_text);
    }

    public function test_question_with_out_of_list_competency_is_dropped(): void
    {
        [$post, $competencies] = $this->makeLessonPostWithCompetencies();
        $validId = $competencies->first()->id;
        $hallucinatedId = $competencies->max('id') + 999;

        $service = $this->serviceReturning(json_encode([
            'questions' => [
                $this->questionPayload($hallucinatedId, ['question' => 'Bad competency']),
                $this->questionPayload($validId, ['question' => 'Good competency']),
            ],
        ]));

        $quiz = $service->generateForPost($post);

        $this->assertCount(1, $quiz->questions);
        $this->assertSame('Good competency', $quiz->questions->first()->question_text);
    }

    public function test_question_with_invalid_difficulty_is_dropped(): void
    {
        [$post, $competencies] = $this->makeLessonPostWithCompetencies();
        $competencyId = $competencies->first()->id;

        $service = $this->serviceReturning(json_encode([
            'questions' => [
                $this->questionPayload($competencyId, ['question' => 'Bad difficulty', 'difficulty' => 'impossible']),
                $this->questionPayload($competencyId, ['question' => 'Good difficulty']),
            ],
        ]));

        $quiz = $service->generateForPost($post);

        $this->assertCount(1, $quiz->questions);
        $this->assertSame('Good difficulty', $quiz->questions->first()->question_text);
    }

    public function test_all_invalid_batch_throws_and_persists_nothing(): void
    {
        [$post, $competencies] = $this->makeLessonPostWithCompetencies();

        $service = $this->serviceReturning(json_encode([
            'questions' => [
                $this->questionPayload($competencies->first()->id, ['correct_answer' => 'Nope']),
            ],
        ]));

        $this->expectException(InvalidAiResponseException::class);

        try {
            $service->generateForPost($post);
        } finally {
            $this->assertDatabaseCount('quizzes', 0);
            $this->assertDatabaseCount('quiz_questions', 0);
        }
    }

    public function test_response_wrapped_in_prose_still_parses(): void
    {
        [$post, $competencies] = $this->makeLessonPostWithCompetencies();
        $competencyId = $competencies->first()->id;

        $service = $this->serviceReturning(
            'Sure! Here you go: '.json_encode(['questions' => [$this->questionPayload($competencyId)]])
        );

        $quiz = $service->generateForPost($post);

        $this->assertCount(1, $quiz->questions);
    }

    public function test_no_competencies_defined_throws_before_calling_llama(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $subject = $professor->ownedSubjects()->create(['name' => 'Chemistry']);
        $class = $subject->sections()->create(['professor_id' => $professor->id, 'name' => 'STEM A']);

        $post = ClassPost::create([
            'class_id' => $class->id,
            'author_id' => $professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Atomic Structure',
            'attachment_path' => 'class-posts/1/lesson.pdf',
            'attachment_name' => 'lesson.pdf',
        ]);

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldNotReceive('chat');

        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldNotReceive('extractText');

        $service = new QuizGenerationService($llama, new PromptService, $extractor, new QuizFeedbackService);

        $this->expectException(RuntimeException::class);
        $service->generateForPost($post);
    }

    /**
     * Uses a real LlamaService (mocked at the HTTP layer, like
     * LlamaServiceTest) instead of a mocked LlamaService, so this exercises
     * the actual primary-fails/fallback-succeeds path end-to-end through
     * quiz generation — not just that QuizGenerationService calls chat().
     */
    public function test_falls_back_to_deepseek_when_llama_is_unavailable(): void
    {
        config([
            'services.routeway.api_key' => 'test-key',
            'services.routeway.model' => 'llama-3.3-70b-instruct',
            'services.routeway.fallback_model' => 'deepseek-v4-flash',
            'services.routeway.max_retries' => 2,
            'services.routeway.retry_delay_ms' => 1,
        ]);

        [$post, $competencies] = $this->makeLessonPostWithCompetencies();
        $competencyId = $competencies->first()->id;

        $quizJson = json_encode([
            'questions' => [$this->questionPayload($competencyId)],
        ]);

        $stack = HandlerStack::create(new MockHandler([
            // Primary model (llama) exhausts all 3 attempts.
            new Response(502, [], 'error code: 502'),
            new Response(502, [], 'error code: 502'),
            new Response(502, [], 'error code: 502'),
            // Fallback model (deepseek) succeeds on its first attempt.
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => $quizJson]]],
            ])),
        ]));
        $guzzle = new Client(['handler' => $stack, 'base_uri' => 'https://api.routeway.ai/v1/']);
        $llama = new LlamaService($guzzle);

        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldReceive('extractText')->once()
            ->andReturn('Some lesson content about atomic structure.');

        $service = new QuizGenerationService($llama, new PromptService, $extractor, new QuizFeedbackService);

        $quiz = $service->generateForPost($post);

        $this->assertSame('deepseek-v4-flash', $quiz->model);
        $this->assertCount(1, $quiz->questions);
        $this->assertDatabaseHas('quizzes', ['id' => $quiz->id, 'model' => 'deepseek-v4-flash']);
    }

    public function test_regenerate_throws_when_no_current_quiz_exists(): void
    {
        [$post] = $this->makeLessonPostWithCompetencies();

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldNotReceive('chat');

        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldNotReceive('extractText');

        $service = new QuizGenerationService($llama, new PromptService, $extractor, new QuizFeedbackService);

        $this->expectException(RuntimeException::class);
        $service->regenerateForPost($post);
    }

    public function test_regenerate_archives_old_quiz_and_preserves_attempt_history(): void
    {
        [$post, $competencies] = $this->makeLessonPostWithCompetencies();
        $competencyId = $competencies->first()->id;

        $oldQuiz = Quiz::create([
            'class_post_id' => $post->id,
            'model' => 'llama-3.3-70b-instruct',
            'generated_at' => now(),
        ]);
        $oldQuestion = $oldQuiz->questions()->create([
            'competency_id' => $competencyId,
            'question_text' => 'Old question',
            'choices' => ['A', 'B'],
            'correct_answer' => 'A',
            'explanation' => 'Because A.',
            'difficulty' => 'easy',
        ]);

        $student = User::factory()->create(['role' => 'student']);
        $attempt = QuizAttempt::create([
            'quiz_id' => $oldQuiz->id,
            'student_id' => $student->id,
            'started_at' => now(),
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);
        QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'quiz_question_id' => $oldQuestion->id,
            'competency_id' => $competencyId,
            'selected_index' => 0,
            'is_correct' => true,
            'answered_at' => now(),
        ]);

        $service = $this->serviceReturning(json_encode([
            'questions' => [$this->questionPayload($competencyId, ['question' => 'New question'])],
        ]));

        $newQuiz = $service->regenerateForPost($post->fresh());

        $this->assertNotSame($oldQuiz->id, $newQuiz->id);
        $this->assertNull($newQuiz->archived_at);
        $this->assertNotNull($oldQuiz->fresh()->archived_at);
        $this->assertSame($newQuiz->id, $post->fresh()->quiz->id);

        // History untouched — still points at the archived quiz.
        $this->assertDatabaseHas('quiz_attempts', ['id' => $attempt->id, 'quiz_id' => $oldQuiz->id]);
        $this->assertDatabaseHas('quiz_answers', ['quiz_attempt_id' => $attempt->id, 'quiz_question_id' => $oldQuestion->id]);
        $this->assertSame(2, Quiz::where('class_post_id', $post->id)->count());
    }

    public function test_regenerate_passes_feedback_context_to_prompt_service(): void
    {
        [$post, $competencies] = $this->makeLessonPostWithCompetencies();
        $competencyId = $competencies->first()->id;

        $oldQuiz = Quiz::create([
            'class_post_id' => $post->id,
            'model' => 'llama-3.3-70b-instruct',
            'generated_at' => now(),
        ]);
        $oldQuiz->questions()->create([
            'competency_id' => $competencyId,
            'question_text' => 'Old question',
            'choices' => ['A', 'B'],
            'correct_answer' => 'A',
            'explanation' => 'Because A.',
            'difficulty' => 'easy',
        ]);

        $student = User::factory()->create(['role' => 'student']);
        $attempt = QuizAttempt::create([
            'quiz_id' => $oldQuiz->id,
            'student_id' => $student->id,
            'started_at' => now(),
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);
        (new QuizFeedbackService)->submit($attempt, $student, [
            'rating' => 2,
            'difficulty' => 'too_hard',
            'comment' => 'Way too confusing.',
        ]);

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->once()->andReturn([
            'content' => json_encode(['questions' => [$this->questionPayload($competencyId)]]),
            'model' => 'llama-3.3-70b-instruct',
        ]);

        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldReceive('extractText')->once()
            ->andReturn('Some lesson content about atomic structure.');

        $prompts = Mockery::mock(PromptService::class);
        $prompts->shouldReceive('quizGenerationMessages')
            ->once()
            ->withArgs(function (...$args) {
                $feedbackContext = $args[5] ?? null;

                return is_string($feedbackContext) && str_contains($feedbackContext, 'too hard');
            })
            ->andReturn([['role' => 'system', 'content' => 'x'], ['role' => 'user', 'content' => 'y']]);

        $service = new QuizGenerationService($llama, $prompts, $extractor, new QuizFeedbackService);

        $service->regenerateForPost($post->fresh());
    }

    public function test_regenerate_without_feedback_passes_null_context(): void
    {
        [$post, $competencies] = $this->makeLessonPostWithCompetencies();
        $competencyId = $competencies->first()->id;

        $oldQuiz = Quiz::create([
            'class_post_id' => $post->id,
            'model' => 'llama-3.3-70b-instruct',
            'generated_at' => now(),
        ]);
        $oldQuiz->questions()->create([
            'competency_id' => $competencyId,
            'question_text' => 'Old question',
            'choices' => ['A', 'B'],
            'correct_answer' => 'A',
            'explanation' => 'Because A.',
            'difficulty' => 'easy',
        ]);

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->once()->andReturn([
            'content' => json_encode(['questions' => [$this->questionPayload($competencyId)]]),
            'model' => 'llama-3.3-70b-instruct',
        ]);

        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldReceive('extractText')->once()
            ->andReturn('Some lesson content about atomic structure.');

        $prompts = Mockery::mock(PromptService::class);
        $prompts->shouldReceive('quizGenerationMessages')
            ->once()
            ->withArgs(fn (...$args) => count($args) < 6 || $args[5] === null)
            ->andReturn([['role' => 'system', 'content' => 'x'], ['role' => 'user', 'content' => 'y']]);

        $service = new QuizGenerationService($llama, $prompts, $extractor, new QuizFeedbackService);

        $newQuiz = $service->regenerateForPost($post->fresh());

        $this->assertNotSame($oldQuiz->id, $newQuiz->id);
    }
}
