<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Quiz;
use App\Models\User;
use App\Notifications\ClassPostPublished;
use App\Notifications\MasteryLevelChanged;
use App\Notifications\QuizFeedbackReceived;
use App\Notifications\StudentJoinedClass;
use App\Notifications\StudentNeedsIntervention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SystemAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $professor;

    private User $student;

    private int $classId;

    private int $competencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professor = User::factory()->create(['role' => 'professor', 'name' => 'Prof Cruz']);
        $this->student = User::factory()->create(['role' => 'student', 'name' => 'Juan Dela Cruz']);

        $subjectId = $this->actingAs($this->professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $this->classId = $this->actingAs($this->professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Class A',
            'section' => 'STEM A',
        ])->json('id');
        $this->competencyId = $this->actingAs($this->professor)->postJson("/professor/subjects/{$subjectId}/competencies", [
            'name' => 'Stoichiometry',
        ])->json('id');

        $this->join($this->student);
    }

    private function join(User $student): void
    {
        $code = ClassRoom::find($this->classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();
    }

    /** A lesson with a 2-question quiz on one competency; index 0 is correct. */
    private function lessonWithQuiz(): array
    {
        $post = ClassPost::create([
            'class_id' => $this->classId,
            'author_id' => $this->professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Mole Concept',
            'attachment_path' => 'class-posts/mole.pdf',
            'attachment_name' => 'mole.pdf',
        ]);
        $quiz = Quiz::create(['class_post_id' => $post->id, 'model' => 'llama', 'generated_at' => now()]);

        $questions = collect([0, 1])->map(fn ($i) => $quiz->questions()->create([
            'competency_id' => $this->competencyId,
            'question_text' => "Question {$i}",
            'choices' => ['Right', 'Wrong', 'Nope', 'No'],
            'correct_answer' => 'Right',
            'explanation' => 'Because.',
            'difficulty' => 'easy',
            'order_index' => $i,
        ]));

        return [$post, $questions];
    }

    private function submitQuiz(ClassPost $post, $questions, int $selectedIndex): int
    {
        return $this->actingAs($this->student)
            ->postJson("/student/classes/{$this->classId}/posts/{$post->id}/quiz/attempts", [
                'answers' => $questions->map(fn ($q) => ['question_id' => $q->id, 'selected_index' => $selectedIndex])->all(),
            ])
            ->assertCreated()
            ->json('attempt_id');
    }

    private function alertsOf(User $user, string $type)
    {
        return $user->fresh()->notifications()->where('type', $type)->get();
    }

    public function test_new_lesson_post_alerts_enrolled_students_only(): void
    {
        $outsider = User::factory()->create(['role' => 'student']);

        $postId = $this->actingAs($this->professor)->postJson("/professor/classes/{$this->classId}/posts", [
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Mole Concept',
        ])->assertCreated()->json('id');

        $alerts = $this->alertsOf($this->student, ClassPostPublished::class);
        $this->assertCount(1, $alerts);
        $this->assertSame("/pages/student/classwork.html?id={$this->classId}&postId={$postId}", $alerts[0]->data['url']);
        $this->assertStringContainsString('Prof Cruz posted a new lesson', $alerts[0]->data['message']);
        $this->assertStringContainsString('Mole Concept', $alerts[0]->data['message']);

        $this->assertCount(0, $outsider->notifications);
        $this->assertCount(0, $this->alertsOf($this->professor, ClassPostPublished::class));
    }

    public function test_announcement_links_to_the_class_page(): void
    {
        $this->actingAs($this->professor)->postJson("/professor/classes/{$this->classId}/posts", [
            'type' => 'announcement',
            'quarter' => '1st Quarter',
            'title' => 'Lab on Friday',
        ])->assertCreated();

        $alert = $this->alertsOf($this->student, ClassPostPublished::class)->first();
        $this->assertSame("/pages/student/enrolled-class.html?id={$this->classId}", $alert->data['url']);
        $this->assertSame('New announcement', $alert->data['title']);
    }

    public function test_module_upload_alerts_students_of_targeted_classes(): void
    {
        Storage::fake('local');
        $subjectId = ClassRoom::find($this->classId)->subject_id;

        $this->actingAs($this->professor)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Gas Laws',
            'attachment' => UploadedFile::fake()->create('gas.pdf', 100, 'application/pdf'),
            'section_ids' => [$this->classId],
            'quarter' => '2nd Quarter',
        ])->assertCreated();

        $alerts = $this->alertsOf($this->student, ClassPostPublished::class);
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('Gas Laws', $alerts[0]->data['message']);
    }

    public function test_student_joining_alerts_the_professor(): void
    {
        $alerts = $this->alertsOf($this->professor, StudentJoinedClass::class);

        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('Juan Dela Cruz joined', $alerts[0]->data['message']);
        $this->assertSame("/pages/professor/professor-class.html?id={$this->classId}", $alerts[0]->data['url']);
    }

    public function test_falling_into_low_alerts_student_and_professor_once(): void
    {
        [$post, $questions] = $this->lessonWithQuiz();

        $this->submitQuiz($post, $questions, 1); // both wrong — first assessment lands in Low

        $studentAlerts = $this->alertsOf($this->student, MasteryLevelChanged::class);
        $this->assertCount(1, $studentAlerts);
        $this->assertSame('mastery_low', $studentAlerts[0]->data['kind']);
        $this->assertStringContainsString('Stoichiometry is Low', $studentAlerts[0]->data['message']);

        $professorAlerts = $this->alertsOf($this->professor, StudentNeedsIntervention::class);
        $this->assertCount(1, $professorAlerts);
        $this->assertStringContainsString("Juan Dela Cruz's mastery of Stoichiometry is Low", $professorAlerts[0]->data['message']);

        // Still Low after another failing quiz — no repeat alerts.
        $this->submitQuiz($post, $questions, 1);
        $this->assertCount(1, $this->alertsOf($this->student, MasteryLevelChanged::class));
        $this->assertCount(1, $this->alertsOf($this->professor, StudentNeedsIntervention::class));
    }

    public function test_reaching_high_alerts_only_the_student(): void
    {
        [$post, $questions] = $this->lessonWithQuiz();

        $this->submitQuiz($post, $questions, 0); // both right: 0.30 → ~0.90

        $studentAlerts = $this->alertsOf($this->student, MasteryLevelChanged::class);
        $this->assertCount(1, $studentAlerts);
        $this->assertSame('mastery_high', $studentAlerts[0]->data['kind']);
        $this->assertCount(0, $this->alertsOf($this->professor, StudentNeedsIntervention::class));
    }

    public function test_quiz_feedback_alerts_the_professor(): void
    {
        [$post, $questions] = $this->lessonWithQuiz();
        $attemptId = $this->submitQuiz($post, $questions, 0);

        $this->actingAs($this->student)
            ->postJson("/student/classes/{$this->classId}/posts/{$post->id}/quiz/attempts/{$attemptId}/feedback", [
                'rating' => 4,
                'difficulty' => 'too_hard',
            ])->assertCreated();

        $alerts = $this->alertsOf($this->professor, QuizFeedbackReceived::class);
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('4/5 (too hard)', $alerts[0]->data['message']);
    }

    public function test_api_lists_marks_read_and_clears_own_alerts(): void
    {
        $this->actingAs($this->professor)->postJson("/professor/classes/{$this->classId}/posts", [
            'type' => 'announcement', 'quarter' => '1st Quarter', 'title' => 'One',
        ]);
        $this->actingAs($this->professor)->postJson("/professor/classes/{$this->classId}/posts", [
            'type' => 'announcement', 'quarter' => '1st Quarter', 'title' => 'Two',
        ]);

        $res = $this->actingAs($this->student)->getJson('/notifications')->assertOk();
        $res->assertJsonPath('unread_count', 2);
        $res->assertJsonCount(2, 'notifications');
        $res->assertJsonPath('notifications.0.read', false);
        $firstId = $res->json('notifications.0.id');

        $this->actingAs($this->student)->postJson("/notifications/{$firstId}/read")->assertOk();
        $this->actingAs($this->student)->getJson('/notifications')->assertJsonPath('unread_count', 1);

        $this->actingAs($this->student)->postJson('/notifications/read-all')->assertOk();
        $this->actingAs($this->student)->getJson('/notifications')->assertJsonPath('unread_count', 0);

        // Another user can't touch this student's alerts.
        $this->actingAs($this->professor)->postJson("/notifications/{$firstId}/read")->assertNotFound();

        $this->actingAs($this->student)->deleteJson('/notifications')->assertNoContent();
        $this->actingAs($this->student)->getJson('/notifications')
            ->assertJsonCount(0, 'notifications')
            ->assertJsonPath('unread_count', 0);

        // The professor's own alerts (student joined) are untouched.
        $this->assertCount(1, $this->professor->fresh()->notifications);
    }

    public function test_guests_cannot_read_alerts(): void
    {
        // setUp() acted as users to build fixtures; drop that session first.
        $this->app['auth']->forgetGuards();

        $this->getJson('/notifications')->assertUnauthorized();
    }
}
