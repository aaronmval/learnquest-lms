<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\AI\LlamaService;
use App\Services\Documents\PdfTextExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class QuizStudioTest extends TestCase
{
    use RefreshDatabase;

    private User $professor;

    private User $student;

    private int $classId;

    private int $competencyId;

    private ClassPost $post;

    private Quiz $quiz;

    /** @var array<string, QuizQuestion> */
    private array $questions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldReceive('extractText')->andReturn(
            "Atoms have protons, neutrons and electrons. Electrons are negative. Protons are positive. Neutrons are neutral. The nucleus holds protons and neutrons."
        );
        $this->app->instance(PdfTextExtractorService::class, $extractor);

        $this->professor = User::factory()->create(['role' => 'professor']);
        $this->student = User::factory()->create(['role' => 'student', 'name' => 'Alice Reyes']);

        $subjectId = $this->actingAs($this->professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $this->classId = $this->actingAs($this->professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Class A',
            'section' => 'STEM A',
        ])->json('id');
        $this->competencyId = $this->actingAs($this->professor)
            ->postJson("/professor/subjects/{$subjectId}/competencies", ['name' => 'Atomic Structure'])->json('id');

        $code = ClassRoom::find($this->classId)->code;
        $this->actingAs($this->student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $this->post = $this->lessonPost('Atomic Structure');
        $this->quiz = Quiz::create(['class_post_id' => $this->post->id, 'model' => 'llama-3.3-70b-instruct', 'generated_at' => now()]);

        foreach ([
            'electron' => ['Which particle is negative?', ['Proton', 'Neutron', 'Electron', 'Positron'], 'Electron', 'easy'],
            'proton' => ['Which particle is positive?', ['Proton', 'Neutron', 'Electron', 'Photon'], 'Proton', 'easy'],
            'nucleus' => ['What does the nucleus contain?', ['Protons and neutrons', 'Electrons only', 'Nothing', 'Photons'], 'Protons and neutrons', 'medium'],
            'charge' => ['An atom with 3 protons and 2 electrons has what charge?', ['+1', '-1', '0', '+3'], '+1', 'hard'],
        ] as $key => [$text, $choices, $correct, $difficulty]) {
            $this->questions[$key] = $this->quiz->questions()->create([
                'competency_id' => $this->competencyId,
                'question_text' => $text,
                'choices' => $choices,
                'correct_answer' => $correct,
                'explanation' => 'Because the lesson says so.',
                'difficulty' => $difficulty,
                'order_index' => count($this->questions),
            ]);
        }
    }

    private function lessonPost(string $title): ClassPost
    {
        return ClassPost::create([
            'class_id' => $this->classId,
            'author_id' => $this->professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => $title,
            'attachment_path' => 'class-posts/lesson.pdf',
            'attachment_name' => 'lesson.pdf',
        ]);
    }

    private function url(string $path, ?ClassPost $post = null): string
    {
        $post ??= $this->post;

        return "/professor/classes/{$this->classId}/posts/{$post->id}/quiz{$path}";
    }

    private function settings(array $overrides = []): array
    {
        return array_merge([
            'question_count' => 6,
            'time_limit_minutes' => null,
            'difficulty_mix' => ['easy' => 30, 'medium' => 40, 'hard' => 30],
            'max_attempts' => null,
            'shuffle_questions' => true,
            'shuffle_choices' => false,
            'show_answers' => true,
            // Adaptive selection is covered in AdaptiveQuizTest.
            'adaptive' => false,
        ], $overrides);
    }

    private function saveSettings(array $overrides = []): void
    {
        $this->actingAs($this->professor)->putJson($this->url('/settings'), $this->settings($overrides))->assertOk();
    }

    private function review(string $key, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->professor)
            ->putJson($this->url("/questions/{$this->questions[$key]->id}/review"), $body);
    }

    private function studentAnswers(array $selected): array
    {
        return array_map(fn ($key, $index) => ['question_id' => $this->questions[$key]->id, 'selected_index' => $index], array_keys($selected), $selected);
    }

    private function submit(array $selected): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->student)->postJson(
            "/student/classes/{$this->classId}/posts/{$this->post->id}/quiz/attempts",
            ['answers' => $this->studentAnswers($selected)],
        );
    }

    private function questionJson(string $text, string $difficulty): array
    {
        return [
            'question' => $text,
            'choices' => ['A '.$text, 'B '.$text, 'C '.$text, 'D '.$text],
            'correct_answer' => 'A '.$text,
            'explanation' => 'Explained.',
            'competency_id' => $this->competencyId,
            'difficulty' => $difficulty,
        ];
    }

    /* ── Lessons & settings ── */

    public function test_lessons_list_only_owned_sections_with_quiz_status(): void
    {
        $other = User::factory()->create(['role' => 'professor']);
        $otherSubject = $this->actingAs($other)->postJson('/professor/subjects', ['name' => 'Physics'])->json('id');
        $this->actingAs($other)->postJson("/professor/subjects/{$otherSubject}/sections", ['name' => 'B', 'section' => 'STEM B']);

        $this->review('proton', ['verdict' => 'rejected', 'reason' => 'unclear']);

        $res = $this->actingAs($this->professor)->getJson('/professor/quiz-studio/lessons')->assertOk();

        $res->assertJsonCount(1, 'classes');
        $res->assertJsonPath('classes.0.label', 'Chemistry - STEM A');
        $res->assertJsonPath('classes.0.lessons.0.id', $this->post->id);
        $res->assertJsonPath('classes.0.lessons.0.has_quiz', true);
        $res->assertJsonPath('classes.0.lessons.0.question_count', 3);
        $res->assertJsonPath('classes.0.lessons.0.rejected', 1);
        $res->assertJsonPath('classes.0.lessons.0.reviewed', 1);
        // Default settings adapt to mastery: 6 questions per student from a
        // bank of 8 (3 easy / 2 medium / 3 hard) covering every level's mix.
        $res->assertJsonPath('classes.0.lessons.0.target_count', 8);
    }

    public function test_settings_default_then_save_and_prefill_the_next_lesson(): void
    {
        $this->actingAs($this->professor)->getJson($this->url('/studio'))
            ->assertOk()
            ->assertJsonPath('settings.saved', false)
            ->assertJsonPath('settings.question_count', 6)
            ->assertJsonPath('settings.time_limit_minutes', null)
            ->assertJsonCount(4, 'questions');

        $this->actingAs($this->professor)->putJson($this->url('/settings'), $this->settings([
            'question_count' => 10,
            'time_limit_minutes' => 20,
            'difficulty_mix' => ['easy' => 20, 'medium' => 40, 'hard' => 40],
            'max_attempts' => 2,
        ]))->assertOk()
            ->assertJsonPath('settings.saved', true)
            ->assertJsonPath('allocation', ['easy' => 2, 'medium' => 4, 'hard' => 4]);

        // A new lesson starts from the teacher's latest settings.
        $next = $this->lessonPost('Periodic Table');
        $this->actingAs($this->professor)->getJson($this->url('/studio', $next))
            ->assertOk()
            ->assertJsonPath('settings.saved', false)
            ->assertJsonPath('settings.question_count', 10)
            ->assertJsonPath('settings.time_limit_minutes', 20)
            ->assertJsonPath('settings.max_attempts', 2)
            ->assertJsonPath('quiz', null);
    }

    public function test_settings_are_validated(): void
    {
        $this->actingAs($this->professor)->putJson($this->url('/settings'), $this->settings([
            'question_count' => 40,
            'difficulty_mix' => ['easy' => 50, 'medium' => 50, 'hard' => 50],
            'time_limit_minutes' => 0,
        ]))->assertStatus(422)->assertJsonValidationErrors(['question_count', 'difficulty_mix', 'time_limit_minutes']);
    }

    public function test_other_professors_and_students_are_blocked(): void
    {
        $other = User::factory()->create(['role' => 'professor']);

        $this->actingAs($other)->getJson($this->url('/studio'))->assertNotFound();
        $this->actingAs($other)->putJson($this->url('/settings'), $this->settings())->assertNotFound();
        $this->actingAs($other)->putJson($this->url("/questions/{$this->questions['proton']->id}/review"), ['verdict' => 'approved'])
            ->assertNotFound();
        $this->actingAs($other)->getJson("/professor/quiz-studio/training?class_id={$this->classId}")->assertNotFound();

        $this->actingAs($this->student)->getJson($this->url('/studio'))->assertRedirect('/login');

        // A question from another lesson can't be reviewed through this one.
        $otherPost = $this->lessonPost('Other');
        $this->actingAs($this->professor)
            ->putJson($this->url("/questions/{$this->questions['proton']->id}/review", $otherPost), ['verdict' => 'approved'])
            ->assertNotFound();
    }

    public function test_collaborator_sets_up_quizzes_for_their_own_sections_only(): void
    {
        Storage::fake('local');
        $collaborator = User::factory()->create(['role' => 'professor']);
        $subjectId = ClassRoom::find($this->classId)->subject_id;
        $this->actingAs($this->professor)
            ->postJson("/professor/subjects/{$subjectId}/collaborators", ['email' => $collaborator->email])
            ->assertCreated();

        // The owner's section is not the collaborator's to set up.
        $this->actingAs($collaborator)->getJson('/professor/quiz-studio/lessons')->assertOk()->assertJsonCount(0, 'classes');
        $this->actingAs($collaborator)->getJson($this->url('/studio'))->assertNotFound();
        $this->actingAs($collaborator)->putJson($this->url('/settings'), $this->settings())->assertNotFound();
        $this->actingAs($collaborator)->getJson("/professor/quiz-studio/training?class_id={$this->classId}")->assertNotFound();

        // Their own standalone class, once a module of the subject is posted
        // into it, is linked to the subject and gets its competencies.
        $ownClassId = $this->actingAs($collaborator)
            ->postJson('/professor/classes', ['name' => 'Chemistry', 'section' => 'STEM C'])->json('id');
        $this->actingAs($collaborator)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Isotopes',
            'attachment' => UploadedFile::fake()->create('isotopes.pdf', 200, 'application/pdf'),
            'section_ids' => [$ownClassId, $this->classId],
        ])->assertCreated();

        $this->assertSame($subjectId, ClassRoom::find($ownClassId)->subject_id);
        $post = ClassPost::where('class_id', $ownClassId)->firstOrFail();
        // The owner's section was ticked too, but isn't the collaborator's to post into.
        $this->assertSame(0, ClassPost::where('class_id', $this->classId)->where('title', 'Isotopes')->count());

        $this->actingAs($collaborator)->getJson('/professor/quiz-studio/lessons')
            ->assertOk()
            ->assertJsonCount(1, 'classes')
            ->assertJsonPath('classes.0.id', $ownClassId)
            ->assertJsonPath('classes.0.has_subject', true)
            ->assertJsonPath('classes.0.lessons.0.id', $post->id);

        $studioUrl = "/professor/classes/{$ownClassId}/posts/{$post->id}/quiz";
        $this->actingAs($collaborator)->getJson("{$studioUrl}/studio")
            ->assertOk()
            ->assertJsonPath('competencies.0.id', $this->competencyId);
        $this->actingAs($collaborator)->putJson("{$studioUrl}/settings", $this->settings(['max_attempts' => 2]))->assertOk();
        $this->actingAs($collaborator)->getJson("/professor/quiz-studio/training?class_id={$ownClassId}")
            ->assertOk()
            ->assertJsonPath('subject.id', $subjectId);

        // A student of the collaborator's class can take the lesson's quiz.
        $quiz = Quiz::create(['class_post_id' => $post->id, 'model' => 'llama-3.3-70b-instruct', 'generated_at' => now()]);
        $question = $quiz->questions()->create([
            'competency_id' => $this->competencyId,
            'question_text' => 'Isotopes differ in the number of what?',
            'choices' => ['Neutrons', 'Protons', 'Electrons', 'Shells'],
            'correct_answer' => 'Neutrons',
            'explanation' => 'Same protons, different neutrons.',
            'difficulty' => 'easy',
            'order_index' => 0,
        ]);

        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => ClassRoom::find($ownClassId)->code])->assertCreated();
        $quizUrl = "/student/classes/{$ownClassId}/posts/{$post->id}/quiz";

        $res = $this->actingAs($student)->getJson($quizUrl)->assertOk()->assertJsonPath('settings.max_attempts', 2);
        $this->assertSame([$question->id], array_column($res->json('questions'), 'id'));

        $this->actingAs($student)
            ->postJson("{$quizUrl}/attempts", ['answers' => [['question_id' => $question->id, 'selected_index' => 0]]])
            ->assertCreated()
            ->assertJsonPath('score.correct', 1);

        // The class now has the subject's competencies for mastery.
        $this->actingAs($student)->getJson("/student/classes/{$ownClassId}/mastery")
            ->assertOk()
            ->assertJsonPath('competency_count', 1);
    }

    /* ── Review ── */

    public function test_review_records_labels_and_rejected_questions_are_hidden_and_not_graded(): void
    {
        // Approve with a difficulty correction: the question takes the teacher's
        // label, the review keeps the AI's original one.
        $this->review('electron', ['verdict' => 'approved', 'teacher_difficulty' => 'medium'])
            ->assertOk()
            ->assertJsonPath('question.difficulty', 'medium')
            ->assertJsonPath('question.ai_difficulty', 'easy')
            ->assertJsonPath('question.review.teacher_difficulty', 'medium');

        $this->review('proton', ['verdict' => 'rejected', 'reason' => 'unclear', 'comment' => 'Too vague.'])
            ->assertOk()
            ->assertJsonPath('question.review.reason', 'unclear');

        $this->assertDatabaseHas('quiz_question_reviews', [
            'quiz_question_id' => $this->questions['electron']->id,
            'verdict' => 'approved',
            'ai_difficulty' => 'easy',
            'teacher_difficulty' => 'medium',
        ]);

        $quiz = $this->actingAs($this->student)->getJson("/student/classes/{$this->classId}/posts/{$this->post->id}/quiz")->assertOk();
        $ids = array_column($quiz->json('questions'), 'id');
        $this->assertCount(3, $ids);
        $this->assertNotContains($this->questions['proton']->id, $ids);

        $this->submit(['electron' => 2, 'proton' => 0, 'nucleus' => 0, 'charge' => 0])
            ->assertCreated()
            ->assertJsonPath('score.total', 3)
            ->assertJsonPath('score.correct', 3);

        // Undo restores the AI label and makes the question active again.
        $this->actingAs($this->professor)->deleteJson($this->url("/questions/{$this->questions['electron']->id}/review"))
            ->assertOk()
            ->assertJsonPath('question.difficulty', 'easy')
            ->assertJsonPath('question.review', null);
    }

    /* ── Generation with settings + training ── */

    public function test_competency_tag_can_be_corrected_and_only_future_answers_follow_it(): void
    {
        $subjectId = ClassRoom::find($this->classId)->subject_id;
        $bondingId = $this->actingAs($this->professor)
            ->postJson("/professor/subjects/{$subjectId}/competencies", ['name' => 'Chemical Bonding'])->json('id');
        $question = $this->questions['charge'];
        $url = $this->url("/questions/{$question->id}/competency");

        // The studio lists the subject's competencies and each question's current tag.
        $studio = $this->actingAs($this->professor)->getJson($this->url('/studio'))->assertOk();
        $this->assertSame(['Atomic Structure', 'Chemical Bonding'], array_column($studio->json('competencies'), 'name'));
        $this->assertSame($this->competencyId, $studio->json('questions.0.competency_id'));
        $this->assertNull($studio->json('questions.0.ai_competency'));

        // An answer recorded before the correction keeps the old competency.
        $this->submit(['charge' => 0])->assertCreated();

        $this->actingAs($this->professor)->putJson($url, ['competency_id' => $bondingId])
            ->assertOk()
            ->assertJsonPath('question.competency', 'Chemical Bonding')
            ->assertJsonPath('question.competency_id', $bondingId)
            ->assertJsonPath('question.ai_competency', 'Atomic Structure');

        $this->assertDatabaseHas('quiz_questions', [
            'id' => $question->id, 'competency_id' => $bondingId, 'ai_competency_id' => $this->competencyId,
        ]);
        $this->assertDatabaseHas('quiz_answers', ['quiz_question_id' => $question->id, 'competency_id' => $this->competencyId]);
        $this->assertDatabaseMissing('quiz_answers', ['quiz_question_id' => $question->id, 'competency_id' => $bondingId]);

        // New answers count toward the corrected competency.
        $this->submit(['charge' => 0])->assertCreated();
        $this->assertDatabaseHas('quiz_answers', ['quiz_question_id' => $question->id, 'competency_id' => $bondingId]);

        // Changing it back to the AI's tag clears the correction marker.
        $this->actingAs($this->professor)->putJson($url, ['competency_id' => $this->competencyId])
            ->assertOk()
            ->assertJsonPath('question.ai_competency', null);
        $this->assertDatabaseHas('quiz_questions', ['id' => $question->id, 'ai_competency_id' => null]);
    }

    public function test_competency_tag_must_belong_to_the_subject_and_the_owner(): void
    {
        $question = $this->questions['charge'];
        $url = $this->url("/questions/{$question->id}/competency");

        $otherSubjectId = $this->actingAs($this->professor)->postJson('/professor/subjects', ['name' => 'Physics'])->json('id');
        $forcesId = $this->actingAs($this->professor)
            ->postJson("/professor/subjects/{$otherSubjectId}/competencies", ['name' => 'Forces'])->json('id');

        $this->actingAs($this->professor)->putJson($url, ['competency_id' => $forcesId])->assertStatus(422);
        $this->actingAs($this->professor)->putJson($url, ['competency_id' => 999999])->assertStatus(422);
        $this->actingAs($this->professor)->putJson($url, [])->assertStatus(422);

        $stranger = User::factory()->create(['role' => 'professor']);
        $this->actingAs($stranger)->putJson($url, ['competency_id' => $this->competencyId])->assertNotFound();

        $this->assertSame($this->competencyId, $question->fresh()->competency_id);
    }

    public function test_generate_uses_settings_and_includes_the_teachers_training_data(): void
    {
        $this->saveSettings(['question_count' => 10, 'difficulty_mix' => ['easy' => 20, 'medium' => 40, 'hard' => 40]]);
        $this->review('electron', ['verdict' => 'approved']);
        $this->review('proton', ['verdict' => 'rejected', 'reason' => 'incorrect_answer', 'comment' => 'The key was wrong.']);

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chatConcurrent')->once()->andReturnUsing(function (array $conversations, array $options, callable $parse) {
            // 10 questions → two parallel batches of 5 with the mix dealt evenly.
            $this->assertCount(2, $conversations);
            $this->assertSame(35, $options['timeout']);

            foreach ($conversations as $i => $messages) {
                $system = $messages[0]['content'];
                $this->assertStringContainsString('Generate exactly 5 questions: 1 "easy", 2 "medium" and 2 "hard"', $system);
                $this->assertStringContainsString('batch '.($i + 1).' of 2', $system);
                $this->assertStringContainsString('TEACHER REVIEW DATA', $system);
                $this->assertStringContainsString('Approved easy question: Which particle is negative?', $system);
                $this->assertStringContainsString('incorrect answer key (1)', $system);
                $this->assertStringContainsString('The key was wrong.', $system);
                $this->assertStringNotContainsString('Alice', $system);
            }

            return array_map(fn ($i) => [
                'value' => $parse(json_encode(['questions' => [
                    $this->questionJson("Batch {$i} Q1", 'easy'),
                    $this->questionJson("Batch {$i} Q2", 'medium'),
                    $this->questionJson("Batch {$i} Q3", 'medium'),
                    $this->questionJson("Batch {$i} Q4", 'hard'),
                    $this->questionJson("Batch {$i} Q5", 'hard'),
                ]]), $i),
                'model' => 'llama-3.3-70b-instruct',
            ], array_keys($conversations));
        });
        $this->app->instance(LlamaService::class, $llama);

        $this->actingAs($this->professor)->postJson($this->url('/generate'))
            ->assertCreated()
            ->assertJsonPath('question_count', 10);

        $this->assertNotNull($this->quiz->fresh()->archived_at);
        $this->assertSame(10, $this->post->fresh()->quiz->questions()->count());
    }

    public function test_top_up_replaces_rejected_questions_without_duplicates(): void
    {
        $this->saveSettings(['question_count' => 5]);
        $this->review('charge', ['verdict' => 'rejected', 'reason' => 'too_hard']);

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->once()->withArgs(function (array $messages) {
            $system = $messages[0]['content'];

            // 5 wanted, 3 active → 2 new ones, told what already exists.
            return str_contains($system, 'Generate exactly 2 questions')
                && str_contains($system, 'already contains these questions')
                && str_contains($system, 'What does the nucleus contain?')
                && ! str_contains($system, 'An atom with 3 protons');
        })->andReturn([
            'content' => json_encode(['questions' => [
                $this->questionJson('What does the nucleus contain', 'medium'), // duplicate → dropped
                $this->questionJson('Where are electrons found?', 'medium'),
                $this->questionJson('Why is an atom neutral?', 'hard'),
            ]]),
            'model' => 'llama-3.3-70b-instruct',
        ]);
        $this->app->instance(LlamaService::class, $llama);

        $this->actingAs($this->professor)->postJson($this->url('/top-up'))->assertCreated()->assertJsonPath('added', 2);

        $this->assertSame(6, $this->quiz->questions()->count());
        $this->assertSame(5, $this->quiz->activeQuestions()->count());

        // Full again: nothing more to top up.
        $this->actingAs($this->professor)->postJson($this->url('/top-up'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This quiz already has its full number of questions.');
    }

    /* ── Training metrics ── */

    public function test_training_metrics_combine_reviews_and_student_results(): void
    {
        config(['quiz.min_responses_for_calibration' => 1]);

        $this->review('electron', ['verdict' => 'approved', 'teacher_difficulty' => 'medium']);
        $this->review('nucleus', ['verdict' => 'approved']);
        $this->review('proton', ['verdict' => 'rejected', 'reason' => 'unclear']);

        // Everyone misses the "hard" question and gets the medium ones.
        $this->submit(['electron' => 2, 'nucleus' => 0, 'charge' => 1])->assertCreated();

        $metrics = $this->actingAs($this->professor)
            ->getJson("/professor/quiz-studio/training?class_id={$this->classId}")
            ->assertOk()
            ->json('metrics');

        $this->assertSame(3, $metrics['reviewed']);
        $this->assertSame(67, $metrics['approval_rate']);
        $this->assertSame(67, $metrics['difficulty_agreement']);
        $this->assertSame([['from' => 'easy', 'to' => 'medium', 'count' => 1]], $metrics['relabels']);
        $this->assertSame('unclear', $metrics['rejection_reasons'][0]['reason']);

        $byLabel = collect($metrics['by_difficulty'])->keyBy('difficulty');
        $this->assertSame(100, $byLabel['medium']['percent_correct']);
        $this->assertSame(0, $byLabel['hard']['percent_correct']);

        // A "medium" question everyone got right looks "easy" → flagged. The
        // "hard" one at 0% is still closest to "hard", so it isn't relabeled
        // (the prompt calibration still tells the AI it ran hard).
        $flagged = collect($metrics['flagged'])->keyBy('question_id');
        $this->assertSame('easy', $flagged[$this->questions['nucleus']->id]['suggested_difficulty']);
        $this->assertArrayNotHasKey($this->questions['charge']->id, $flagged->all());
        $this->assertArrayNotHasKey($this->questions['proton']->id, $flagged->all());

        // The studio shows the same per-question results.
        $studio = collect($this->actingAs($this->professor)->getJson($this->url('/studio'))->json('questions'))->keyBy('id');
        $this->assertSame(1, $studio[$this->questions['nucleus']->id]['stats']['responses']);
        $this->assertTrue($studio[$this->questions['nucleus']->id]['stats']['flagged']);
    }

    /* ── Student-side settings ── */

    public function test_timed_quiz_keeps_its_clock_on_reload_and_marks_late_submissions(): void
    {
        $this->freezeSecond();
        $this->saveSettings(['time_limit_minutes' => 10]);
        $quizUrl = "/student/classes/{$this->classId}/posts/{$this->post->id}/quiz";

        $first = $this->actingAs($this->student)->getJson($quizUrl)->assertOk();
        $this->assertSame(600, $first->json('settings.time_limit_seconds'));
        $this->assertSame(600, $first->json('settings.remaining_seconds'));

        $this->travel(4)->minutes();
        $this->actingAs($this->student)->getJson($quizUrl)->assertOk()->assertJsonPath('settings.remaining_seconds', 360);
        $this->assertSame(1, QuizAttempt::where('status', 'in_progress')->count());

        $this->travel(8)->minutes(); // past the deadline + 60s grace
        $this->submit(['electron' => 2])->assertCreated()->assertJsonPath('status', 'late');

        $this->assertSame(1, QuizAttempt::count());
        $this->assertSame('late', QuizAttempt::first()->status);
    }

    public function test_on_time_submission_completes_the_open_attempt(): void
    {
        $this->saveSettings(['time_limit_minutes' => 10]);
        $this->actingAs($this->student)->getJson("/student/classes/{$this->classId}/posts/{$this->post->id}/quiz")->assertOk();

        $this->travel(9)->minutes();
        $this->submit(['electron' => 2])->assertCreated()->assertJsonPath('status', 'submitted');
        $this->assertSame(1, QuizAttempt::count());
    }

    public function test_attempt_limit_is_enforced(): void
    {
        $this->saveSettings(['max_attempts' => 1]);
        $quizUrl = "/student/classes/{$this->classId}/posts/{$this->post->id}/quiz";

        $this->actingAs($this->student)->getJson($quizUrl)->assertOk()
            ->assertJsonPath('settings.max_attempts', 1)
            ->assertJsonPath('settings.attempts_used', 0);
        $this->submit(['electron' => 2])->assertCreated();

        $this->actingAs($this->student)->getJson($quizUrl)->assertForbidden()
            ->assertJsonPath('message', "You've used all 1 attempt(s) allowed for this quiz.");
        $this->submit(['electron' => 2])->assertForbidden();
    }

    public function test_hidden_answers_keep_right_and_wrong_but_not_the_key(): void
    {
        $this->saveSettings(['show_answers' => false]);

        $review = collect($this->submit(['electron' => 0, 'nucleus' => 0])->assertCreated()->json('review'))->keyBy('question_id');

        $this->assertFalse($review[$this->questions['electron']->id]['is_correct']);
        $this->assertNull($review[$this->questions['electron']->id]['correct_index']);
        $this->assertNull($review[$this->questions['electron']->id]['explanation']);
        $this->assertTrue($review[$this->questions['nucleus']->id]['is_correct']);
    }

    public function test_shuffled_choices_are_graded_by_original_index(): void
    {
        $this->saveSettings(['shuffle_choices' => true, 'shuffle_questions' => false]);

        $quiz = $this->actingAs($this->student)
            ->getJson("/student/classes/{$this->classId}/posts/{$this->post->id}/quiz")
            ->assertOk();

        $first = $quiz->json('questions.0');
        $this->assertSame($this->questions['electron']->id, $first['id']);
        $this->assertEqualsCanonicalizing([0, 1, 2, 3], $first['choice_indexes']);

        // The page maps the displayed "Electron" back to its original index.
        $displayed = array_search('Electron', $first['choices'], true);
        $original = $first['choice_indexes'][$displayed];
        $this->assertSame(2, $original);

        $this->submit(['electron' => $original])->assertCreated()->assertJsonPath('score.correct', 1);
    }
}
