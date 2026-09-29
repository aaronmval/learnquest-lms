<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\StudentMastery;
use App\Models\User;
use App\Services\AI\LlamaService;
use App\Services\Documents\PdfTextExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Each student is served questions matched to their BKT mastery of the
 * lesson's competencies: high → harder mix, low → easier, no quiz
 * evidence yet → the teacher's mix.
 */
class AdaptiveQuizTest extends TestCase
{
    use RefreshDatabase;

    private User $professor;

    private int $classId;

    private int $competencyId;

    private ClassPost $post;

    private Quiz $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bkt.mastery_threshold_low' => 0.40, 'bkt.mastery_threshold_high' => 0.75, 'quiz.adaptive_shift' => 20]);

        $extractor = Mockery::mock(PdfTextExtractorService::class);
        $extractor->shouldReceive('extractText')->andReturn('Atoms have protons. Electrons are negative. Neutrons are neutral.');
        $this->app->instance(PdfTextExtractorService::class, $extractor);

        $this->professor = User::factory()->create(['role' => 'professor']);
        $subjectId = $this->actingAs($this->professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $this->classId = $this->actingAs($this->professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Class A',
            'section' => 'STEM A',
        ])->json('id');
        $this->competencyId = $this->actingAs($this->professor)
            ->postJson("/professor/subjects/{$subjectId}/competencies", ['name' => 'Atomic Structure'])->json('id');

        $this->post = ClassPost::create([
            'class_id' => $this->classId,
            'author_id' => $this->professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Atomic Structure',
            'attachment_path' => 'class-posts/lesson.pdf',
            'attachment_name' => 'lesson.pdf',
        ]);
        $this->quiz = Quiz::create(['class_post_id' => $this->post->id, 'model' => 'llama', 'generated_at' => now()]);

        // 5 questions per student at 20/40/40. Bank covering every level:
        // low 40/40/20 → 2/2/1, developing → 1/2/2, high 0/40/60 → 0/2/3.
        $this->saveSettings(['question_count' => 5, 'difficulty_mix' => ['easy' => 20, 'medium' => 40, 'hard' => 40]]);
        $this->addQuestions(['easy' => 2, 'medium' => 2, 'hard' => 3]);
    }

    private function saveSettings(array $overrides = []): void
    {
        $this->actingAs($this->professor)->putJson("/professor/classes/{$this->classId}/posts/{$this->post->id}/quiz/settings", array_merge([
            'question_count' => 5,
            'time_limit_minutes' => null,
            'difficulty_mix' => ['easy' => 20, 'medium' => 40, 'hard' => 40],
            'max_attempts' => null,
            'shuffle_questions' => true,
            'shuffle_choices' => false,
            'show_answers' => true,
            'adaptive' => true,
        ], $overrides))->assertOk();
    }

    private function addQuestions(array $counts): void
    {
        foreach ($counts as $difficulty => $n) {
            for ($i = 1; $i <= $n; $i++) {
                $this->quiz->questions()->create([
                    'competency_id' => $this->competencyId,
                    'question_text' => "{$difficulty} question {$i}",
                    'choices' => ['Right', 'Wrong'],
                    'correct_answer' => 'Right',
                    'explanation' => 'Because.',
                    'difficulty' => $difficulty,
                    'order_index' => $this->quiz->questions()->count(),
                ]);
            }
        }
    }

    /** Enroll a student, optionally with a BKT mastery record for the lesson's competency. */
    private function student(?float $mastery): User
    {
        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => ClassRoom::find($this->classId)->code])->assertCreated();

        if ($mastery !== null) {
            StudentMastery::create([
                'student_id' => $student->id,
                'competency_id' => $this->competencyId,
                'initial_mastery' => 0.30,
                'current_mastery' => $mastery,
                'p_l0' => 0.30,
                'p_t' => 0.15,
                'p_g' => 0.25,
                'p_s' => 0.10,
                'observations_count' => 6,
            ]);
        }

        return $student;
    }

    private function openQuiz(User $student): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($student)->getJson("/student/classes/{$this->classId}/posts/{$this->post->id}/quiz")->assertOk();
    }

    /** Difficulty counts of the questions a student was served. */
    private function servedMix(\Illuminate\Testing\TestResponse $response): array
    {
        $difficulties = QuizQuestion::whereIn('id', array_column($response->json('questions'), 'id'))->pluck('difficulty');

        return array_merge(['easy' => 0, 'medium' => 0, 'hard' => 0], $difficulties->countBy()->all());
    }

    public function test_high_mastery_students_get_a_harder_quiz(): void
    {
        $response = $this->openQuiz($this->student(0.90));

        $response->assertJsonPath('settings.mastery_level', 'high');
        $this->assertSame(['easy' => 0, 'medium' => 2, 'hard' => 3], $this->servedMix($response));
    }

    public function test_low_mastery_students_get_an_easier_quiz(): void
    {
        $response = $this->openQuiz($this->student(0.20));

        $response->assertJsonPath('settings.mastery_level', 'low');
        $this->assertSame(['easy' => 2, 'medium' => 2, 'hard' => 1], $this->servedMix($response));
    }

    public function test_students_without_quiz_results_get_the_teachers_mix(): void
    {
        $response = $this->openQuiz($this->student(null));

        $response->assertJsonPath('settings.mastery_level', null);
        $this->assertSame(['easy' => 1, 'medium' => 2, 'hard' => 2], $this->servedMix($response));
    }

    public function test_teacher_can_turn_adaptivity_off(): void
    {
        $this->saveSettings(['adaptive' => false]);

        $response = $this->openQuiz($this->student(0.90));

        $response->assertJsonPath('settings.mastery_level', null);
        $this->assertSame(['easy' => 1, 'medium' => 2, 'hard' => 2], $this->servedMix($response));
    }

    public function test_reload_keeps_the_same_questions_and_grading_covers_only_them(): void
    {
        $student = $this->student(0.90);

        $first = array_column($this->openQuiz($student)->json('questions'), 'id');
        $second = array_column($this->openQuiz($student)->json('questions'), 'id');
        $this->assertEqualsCanonicalizing($first, $second);

        $attempt = QuizAttempt::where('student_id', $student->id)->sole();
        $this->assertSame('high', $attempt->mastery_level);
        $this->assertEqualsCanonicalizing($first, $attempt->question_ids);

        // Answer all 7 bank questions: only the 5 served ones are graded.
        $answers = $this->quiz->questions()->pluck('id')->map(fn ($id) => ['question_id' => $id, 'selected_index' => 0])->all();
        $this->actingAs($student)
            ->postJson("/student/classes/{$this->classId}/posts/{$this->post->id}/quiz/attempts", ['answers' => $answers])
            ->assertCreated()
            ->assertJsonPath('score.total', 5)
            ->assertJsonPath('score.correct', 5);

        $this->assertSame(5, $attempt->answers()->count());
    }

    public function test_a_short_bank_fills_from_the_nearest_difficulty(): void
    {
        // Reject two hard questions: a high-mastery student still gets 5,
        // with the missing hard ones filled from medium, then easy.
        $hard = $this->quiz->questions()->where('difficulty', 'hard')->take(2)->get();
        foreach ($hard as $question) {
            $this->actingAs($this->professor)
                ->putJson("/professor/classes/{$this->classId}/posts/{$this->post->id}/quiz/questions/{$question->id}/review", ['verdict' => 'rejected'])
                ->assertOk();
        }

        $this->assertSame(['easy' => 2, 'medium' => 2, 'hard' => 1], $this->servedMix($this->openQuiz($this->student(0.90))));
    }

    public function test_feedback_breakdown_groups_perceived_difficulty_by_mastery_level(): void
    {
        $submitWithFeedback = function (User $student, int $rating, string $difficulty, ?string $comment = null) {
            $ids = array_column($this->openQuiz($student)->json('questions'), 'id');
            $attemptId = $this->actingAs($student)
                ->postJson("/student/classes/{$this->classId}/posts/{$this->post->id}/quiz/attempts", [
                    'answers' => array_map(fn ($id) => ['question_id' => $id, 'selected_index' => 0], $ids),
                ])->assertCreated()->json('attempt_id');

            $this->actingAs($student)
                ->postJson("/student/classes/{$this->classId}/posts/{$this->post->id}/quiz/attempts/{$attemptId}/feedback", [
                    'rating' => $rating, 'difficulty' => $difficulty, 'comment' => $comment,
                ])->assertSuccessful();
        };

        $submitWithFeedback($this->student(0.90), 5, 'just_right', 'Challenging in a good way.');
        $submitWithFeedback($this->student(0.95), 4, 'too_hard');
        $submitWithFeedback($this->student(0.20), 2, 'too_easy');

        $breakdown = $this->actingAs($this->professor)
            ->getJson("/professor/classes/{$this->classId}/posts/{$this->post->id}/quiz/studio")
            ->assertOk()
            ->json('quiz.feedback_breakdown');

        $this->assertSame(3, $breakdown['count']);
        $this->assertEquals(3.7, $breakdown['average_rating']);
        $this->assertSame([1 => 0, 2 => 1, 3 => 0, 4 => 1, 5 => 1], $breakdown['ratings']);

        $groups = collect($breakdown['groups'])->keyBy('key');
        $this->assertSame(['all', 'high', 'low'], $groups->keys()->all());
        $this->assertSame([1, 1, 1], [$groups['all']['too_easy'], $groups['all']['just_right'], $groups['all']['too_hard']]);
        $this->assertSame(2, $groups['high']['count']);
        $this->assertSame(1, $groups['high']['too_hard']);
        $this->assertSame(1, $groups['low']['too_easy']);

        $this->assertCount(1, $breakdown['comments']);
        $this->assertSame('high', $breakdown['comments'][0]['level']);
        $this->assertArrayNotHasKey('student_id', $breakdown['comments'][0]);
    }

    public function test_studio_reports_the_bank_and_top_up_fills_it_for_every_level(): void
    {
        $this->actingAs($this->professor)->getJson("/professor/classes/{$this->classId}/posts/{$this->post->id}/quiz/studio")
            ->assertOk()
            ->assertJsonPath('pool', ['easy' => 2, 'medium' => 2, 'hard' => 3])
            ->assertJsonPath('allocation', ['easy' => 1, 'medium' => 2, 'hard' => 2]);

        // Raise the per-student count to 6 → low 3/2/1, developing 1/3/2,
        // high 0/2/4 → bank of easy 3 / medium 3 / hard 4 (10): one more of each.
        $this->saveSettings(['question_count' => 6]);

        $question = fn ($text, $difficulty) => ['question' => $text, 'choices' => ['A', 'B'], 'correct_answer' => 'A', 'explanation' => 'x', 'competency_id' => $this->competencyId, 'difficulty' => $difficulty];

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->once()
            ->withArgs(fn (array $messages) => str_contains($messages[0]['content'], 'Generate exactly 3 questions: 1 "easy", 1 "medium" and 1 "hard"'))
            ->andReturn(['content' => json_encode(['questions' => [
                $question('New easy', 'easy'),
                $question('New medium', 'medium'),
                $question('New hard', 'hard'),
            ]]), 'model' => 'llama']);
        $this->app->instance(LlamaService::class, $llama);

        $this->actingAs($this->professor)->postJson("/professor/classes/{$this->classId}/posts/{$this->post->id}/quiz/top-up")
            ->assertCreated()
            ->assertJsonPath('added', 3);

        $this->assertSame(10, $this->quiz->activeQuestions()->count());
    }
}
