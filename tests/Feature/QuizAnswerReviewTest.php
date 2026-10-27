<?php

namespace Tests\Feature;

use App\Models\AnswerReviewRequest;
use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

/**
 * Answer review permission (students ask, teacher approves/denies) and the
 * leaving-the-quiz counter.
 */
class QuizAnswerReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $professor;

    private User $student;

    private int $classId;

    private ClassPost $post;

    private QuizQuestion $question;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professor = User::factory()->create(['role' => 'professor']);
        $this->student = User::factory()->create(['role' => 'student']);

        $subjectId = $this->actingAs($this->professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $this->classId = $this->actingAs($this->professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Class A',
            'section' => 'STEM A',
        ])->json('id');
        $competencyId = $this->actingAs($this->professor)
            ->postJson("/professor/subjects/{$subjectId}/competencies", ['name' => 'Atomic Structure'])->json('id');

        $this->actingAs($this->student)->postJson('/student/classes/join', ['code' => ClassRoom::find($this->classId)->code])->assertCreated();

        $this->post = ClassPost::create([
            'class_id' => $this->classId,
            'author_id' => $this->professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Atomic Structure',
            'attachment_path' => 'class-posts/lesson.pdf',
            'attachment_name' => 'lesson.pdf',
        ]);
        $quiz = Quiz::create(['class_post_id' => $this->post->id, 'model' => 'llama', 'generated_at' => now()]);

        $this->question = $quiz->questions()->create([
            'competency_id' => $competencyId,
            'question_text' => 'Which particle has a negative charge?',
            'choices' => ['Proton', 'Neutron', 'Electron', 'Positron'],
            'correct_answer' => 'Electron',
            'explanation' => 'Electrons are negatively charged.',
            'difficulty' => 'easy',
            'order_index' => 0,
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/student/classes/{$this->classId}/posts/{$this->post->id}/quiz{$path}";
    }

    private function studioUrl(string $path): string
    {
        return "/professor/classes/{$this->classId}/posts/{$this->post->id}/quiz{$path}";
    }

    /** Open the quiz (starts the attempt) and submit one wrong answer. */
    private function submitAttempt(): int
    {
        $this->actingAs($this->student)->getJson($this->url())->assertOk();

        return $this->actingAs($this->student)->postJson($this->url('/attempts'), [
            'answers' => [['question_id' => $this->question->id, 'selected_index' => 0]],
        ])->assertCreated()->json('attempt_id');
    }

    private function saveSettings(bool $requiresPermission, bool $showAnswers): void
    {
        $this->actingAs($this->professor)->putJson($this->studioUrl('/settings'), [
            'question_count' => 5,
            'time_limit_minutes' => null,
            'difficulty_mix' => ['easy' => 30, 'medium' => 40, 'hard' => 30],
            'max_attempts' => null,
            'shuffle_questions' => false,
            'shuffle_choices' => false,
            'show_answers' => $showAnswers,
            'review_requires_permission' => $requiresPermission,
            'adaptive' => true,
        ])->assertOk()->assertJsonPath('settings.review_requires_permission', $requiresPermission);
    }

    public function test_a_locked_review_returns_only_the_score(): void
    {
        $this->actingAs($this->student)->getJson($this->url())->assertOk();

        $this->actingAs($this->student)->postJson($this->url('/attempts'), [
            'answers' => [['question_id' => $this->question->id, 'selected_index' => 0]],
        ])
            ->assertCreated()
            ->assertJsonPath('score.incorrect', 1)
            ->assertJsonPath('review', [])
            ->assertJsonPath('review_unlocked', false)
            ->assertJsonPath('answers_visible', false)
            ->assertJsonPath('answer_review.status', null);
    }

    public function test_without_permission_and_hidden_key_students_see_right_and_wrong_only(): void
    {
        $this->saveSettings(requiresPermission: false, showAnswers: false);
        $attemptId = $this->submitAttempt();

        $this->actingAs($this->student)->getJson($this->url("/attempts/{$attemptId}/review"))
            ->assertJsonPath('review_unlocked', true)
            ->assertJsonPath('answers_visible', false)
            ->assertJsonPath('review.0.is_correct', false)
            ->assertJsonPath('review.0.correct_index', null);
    }

    public function test_an_approved_review_still_respects_hidden_correct_answers(): void
    {
        $this->saveSettings(requiresPermission: true, showAnswers: false);
        $attemptId = $this->submitAttempt();
        $this->actingAs($this->student)->postJson($this->url("/attempts/{$attemptId}/answer-review"))->assertCreated();
        $this->actingAs($this->professor)
            ->putJson($this->studioUrl('/answer-reviews/'.AnswerReviewRequest::firstOrFail()->id), ['approved' => true]);

        $this->actingAs($this->student)->getJson($this->url("/attempts/{$attemptId}/review"))
            ->assertJsonPath('review_unlocked', true)
            ->assertJsonPath('review.0.is_correct', false)
            ->assertJsonPath('review.0.correct_index', null);
    }

    public function test_review_stays_hidden_until_the_teacher_approves(): void
    {
        $attemptId = $this->submitAttempt();

        $this->actingAs($this->student)->getJson($this->url("/attempts/{$attemptId}/review"))
            ->assertOk()
            ->assertJsonPath('review_unlocked', false)
            ->assertJsonPath('review', [])
            ->assertJsonPath('questions', [])
            ->assertJsonPath('score.incorrect', 1);

        $this->actingAs($this->student)->postJson($this->url("/attempts/{$attemptId}/answer-review"))
            ->assertCreated()
            ->assertJsonPath('status', 'pending');

        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $this->professor->id)
            ->where('data->kind', 'answer_review_requested')->count());

        $this->actingAs($this->student)->getJson($this->url("/attempts/{$attemptId}/review"))
            ->assertJsonPath('review_unlocked', false)
            ->assertJsonPath('answer_review.status', 'pending');

        $requests = $this->actingAs($this->professor)->getJson($this->studioUrl('/answer-reviews'))
            ->assertOk()
            ->assertJsonPath('requests.0.status', 'pending')
            ->assertJsonPath('requests.0.student_name', $this->student->name)
            ->json('requests');

        $this->actingAs($this->professor)->putJson($this->studioUrl("/answer-reviews/{$requests[0]['id']}"), ['approved' => true])
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('pending', 0);

        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $this->student->id)
            ->where('data->kind', 'answer_review_decided')->count());

        $this->actingAs($this->student)->getJson($this->url("/attempts/{$attemptId}/review"))
            ->assertJsonPath('review_unlocked', true)
            ->assertJsonPath('answers_visible', true)
            ->assertJsonPath('questions.0.text', 'Which particle has a negative charge?')
            ->assertJsonPath('review.0.correct_index', 2)
            ->assertJsonPath('review.0.explanation', 'Electrons are negatively charged.');

        $this->actingAs($this->student)->getJson($this->url('/attempts/latest'))
            ->assertJsonPath('attempt.id', $attemptId)
            ->assertJsonPath('attempt.review_unlocked', true)
            ->assertJsonPath('attempt.answer_review_status', 'approved');
    }

    public function test_a_denied_request_can_be_sent_again(): void
    {
        $attemptId = $this->submitAttempt();
        $this->actingAs($this->student)->postJson($this->url("/attempts/{$attemptId}/answer-review"))->assertCreated();

        $request = AnswerReviewRequest::firstOrFail();
        $this->actingAs($this->professor)->putJson($this->studioUrl("/answer-reviews/{$request->id}"), ['approved' => false])
            ->assertJsonPath('status', 'denied');

        $this->actingAs($this->student)->getJson($this->url("/attempts/{$attemptId}/review"))
            ->assertJsonPath('review_unlocked', false)
            ->assertJsonPath('answer_review.status', 'denied');

        $this->actingAs($this->student)->postJson($this->url("/attempts/{$attemptId}/answer-review"))
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseCount('answer_review_requests', 1);
    }

    public function test_only_the_owner_can_see_or_decide_requests_and_students_only_their_own_attempts(): void
    {
        $attemptId = $this->submitAttempt();

        $classmate = User::factory()->create(['role' => 'student']);
        $this->actingAs($classmate)->postJson('/student/classes/join', ['code' => ClassRoom::find($this->classId)->code])->assertCreated();
        $this->actingAs($classmate)->postJson($this->url("/attempts/{$attemptId}/answer-review"))->assertNotFound();
        $this->actingAs($classmate)->getJson($this->url("/attempts/{$attemptId}/review"))->assertNotFound();

        $this->actingAs($this->student)->postJson($this->url("/attempts/{$attemptId}/answer-review"))->assertCreated();
        $request = AnswerReviewRequest::firstOrFail();

        $other = User::factory()->create(['role' => 'professor']);
        $this->actingAs($other)->getJson($this->studioUrl('/answer-reviews'))->assertNotFound();
        $this->actingAs($other)->putJson($this->studioUrl("/answer-reviews/{$request->id}"), ['approved' => true])->assertNotFound();
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_reviews_without_required_permission_need_no_request(): void
    {
        $this->saveSettings(requiresPermission: false, showAnswers: true);

        $attemptId = $this->submitAttempt();

        $this->actingAs($this->student)->getJson($this->url("/attempts/{$attemptId}/review"))
            ->assertJsonPath('answers_visible', true)
            ->assertJsonPath('review.0.correct_index', 2);
        $this->actingAs($this->student)->postJson($this->url("/attempts/{$attemptId}/answer-review"))
            ->assertOk()
            ->assertJsonPath('status', 'approved');
        $this->assertDatabaseCount('answer_review_requests', 0);
    }

    public function test_leaving_the_quiz_is_counted_on_the_open_attempt_only(): void
    {
        $attemptId = $this->actingAs($this->student)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('settings.focus_violations', 0)
            ->assertJsonPath('settings.max_focus_violations', (int) config('quiz.max_focus_violations'))
            ->json('attempt_id');

        $this->actingAs($this->student)->postJson($this->url("/attempts/{$attemptId}/focus-violation"))
            ->assertOk()
            ->assertJsonPath('violations', 1);
        $this->actingAs($this->student)->postJson($this->url("/attempts/{$attemptId}/focus-violation"))
            ->assertJsonPath('violations', 2);

        // A reload keeps the count.
        $this->actingAs($this->student)->getJson($this->url())->assertJsonPath('settings.focus_violations', 2);

        $classmate = User::factory()->create(['role' => 'student']);
        $this->actingAs($classmate)->postJson('/student/classes/join', ['code' => ClassRoom::find($this->classId)->code])->assertCreated();
        $this->actingAs($classmate)->postJson($this->url("/attempts/{$attemptId}/focus-violation"))->assertNotFound();

        $this->actingAs($this->student)->postJson($this->url('/attempts'), [
            'answers' => [['question_id' => $this->question->id, 'selected_index' => 2]],
        ])->assertCreated();
        $this->actingAs($this->student)->postJson($this->url("/attempts/{$attemptId}/focus-violation"))->assertStatus(409);

        $this->assertSame(2, QuizAttempt::find($attemptId)->focus_violations);

        $this->actingAs($this->professor)->getJson($this->studioUrl('/studio'))
            ->assertJsonPath('quiz.focus_violations', 2)
            ->assertJsonPath('quiz.attempts_with_focus_violations', 1);
    }
}
