<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\ClassRoom;
use App\Models\Module;
use App\Models\Quiz;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function professor(string $name = 'Prof'): User
    {
        return User::factory()->create(['role' => 'professor', 'name' => $name]);
    }

    private function deleteAccount(User $user, string $password = 'password')
    {
        return $this->actingAs($user)->deleteJson('/settings/account', ['password' => $password]);
    }

    private function uploadModule(User $professor, int $subjectId, array $sectionIds = []): int
    {
        return $this->actingAs($professor)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Atoms',
            'attachment' => UploadedFile::fake()->create('atoms.pdf', 200, 'application/pdf'),
            'section_ids' => $sectionIds,
        ])->assertCreated()->json('id');
    }

    /** A quiz with one question on the lesson, and one graded attempt by the student. */
    private function quizWithAttempt(ClassPost $post, int $competencyId, User $student): void
    {
        $quiz = Quiz::create(['class_post_id' => $post->id, 'model' => 'llama-3.3-70b-instruct', 'generated_at' => now()]);
        $question = $quiz->questions()->create([
            'competency_id' => $competencyId,
            'question_text' => 'Which particle is negative?',
            'choices' => ['Proton', 'Neutron', 'Electron', 'Positron'],
            'correct_answer' => 'Electron',
            'explanation' => 'Electrons carry negative charge.',
            'difficulty' => 'easy',
            'order_index' => 0,
        ]);

        $this->actingAs($student)->postJson(
            "/student/classes/{$post->class_id}/posts/{$post->id}/quiz/attempts",
            ['answers' => [['question_id' => $question->id, 'selected_index' => 2]]],
        )->assertCreated();
    }

    public function test_the_password_is_required_and_checked(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->deleteAccount($student, 'wrong-password')->assertStatus(422)->assertJsonValidationErrors('password');
        $this->actingAs($student)->deleteJson('/settings/account')->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseHas('users', ['id' => $student->id]);
        $this->assertAuthenticatedAs($student);
    }

    public function test_guests_cannot_delete_an_account(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->deleteJson('/settings/account', ['password' => 'password'])->assertUnauthorized();
        $this->assertDatabaseHas('users', ['id' => $student->id]);
    }

    public function test_a_student_account_and_its_data_are_removed(): void
    {
        $professor = $this->professor();
        $student = User::factory()->create(['role' => 'student']);
        $classmate = User::factory()->create(['role' => 'student']);

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $competencyId = $this->actingAs($professor)
            ->postJson("/professor/subjects/{$subjectId}/competencies", ['name' => 'Atomic Structure'])->json('id');
        $class = $this->actingAs($professor)
            ->postJson("/professor/subjects/{$subjectId}/sections", ['name' => 'Chemistry', 'section' => 'STEM A'])->json();
        $this->uploadModule($professor, $subjectId, [$class['id']]);
        $post = ClassPost::where('class_id', $class['id'])->firstOrFail();

        foreach ([$student, $classmate] as $user) {
            $this->actingAs($user)->postJson('/student/classes/join', ['code' => $class['code']])->assertCreated();
        }
        $this->quizWithAttempt($post, $competencyId, $student);
        // An announcement after joining gives the student a System Alert.
        $this->actingAs($professor)->postJson("/professor/classes/{$class['id']}/posts", [
            'type' => 'announcement',
            'quarter' => '1st Quarter',
            'title' => 'Welcome',
        ])->assertCreated();

        $this->actingAs($student)->post('/settings/avatar', [
            'photo' => UploadedFile::fake()->image('me.png'),
        ], ['Accept' => 'application/json'])->assertOk();
        $avatar = $student->fresh()->avatar_path;
        $this->assertGreaterThan(0, $student->fresh()->notifications()->count());

        $this->deleteAccount($student)->assertOk()->assertJsonPath('redirect', route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $student->id]);
        $this->assertDatabaseMissing('class_enrollments', ['student_id' => $student->id]);
        $this->assertDatabaseMissing('quiz_attempts', ['student_id' => $student->id]);
        $this->assertDatabaseMissing('student_mastery', ['student_id' => $student->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $student->id]);
        $this->assertDatabaseCount('quiz_answers', 0);
        Storage::disk('local')->assertMissing($avatar);

        // Nobody else is affected.
        $this->assertDatabaseHas('class_enrollments', ['student_id' => $classmate->id]);
        $this->assertDatabaseHas('classes', ['id' => $class['id']]);

        // The old credentials no longer sign in.
        $this->post('/login', ['email' => $student->email, 'password' => 'password']);
        $this->assertGuest();
    }

    public function test_a_professors_own_classes_and_solo_subjects_are_removed(): void
    {
        $professor = $this->professor();
        $student = User::factory()->create(['role' => 'student']);

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $competencyId = $this->actingAs($professor)
            ->postJson("/professor/subjects/{$subjectId}/competencies", ['name' => 'Atomic Structure'])->json('id');
        $class = $this->actingAs($professor)
            ->postJson("/professor/subjects/{$subjectId}/sections", ['name' => 'Chemistry', 'section' => 'STEM A'])->json();
        $moduleId = $this->uploadModule($professor, $subjectId, [$class['id']]);
        $post = ClassPost::where('class_id', $class['id'])->firstOrFail();
        $moduleFile = Module::findOrFail($moduleId)->file_path;
        $lessonFile = $post->attachment_path;

        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $class['code']])->assertCreated();
        $this->quizWithAttempt($post, $competencyId, $student);
        Storage::disk('local')->assertExists($moduleFile);
        Storage::disk('local')->assertExists($lessonFile);

        $this->deleteAccount($professor)->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $professor->id]);
        foreach (['classes', 'class_posts', 'subjects', 'modules', 'competencies', 'quizzes', 'quiz_attempts', 'student_mastery'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Storage::disk('local')->assertMissing($moduleFile);
        Storage::disk('local')->assertMissing($lessonFile);

        // The student's account survives; only their data in that class is gone.
        $this->assertDatabaseHas('users', ['id' => $student->id]);
    }

    public function test_a_shared_subject_is_handed_to_the_earliest_collaborator(): void
    {
        $owner = $this->professor('Owner');
        $first = $this->professor('First');
        $second = $this->professor('Second');
        $student = User::factory()->create(['role' => 'student']);

        $subjectId = $this->actingAs($owner)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $competencyId = $this->actingAs($owner)
            ->postJson("/professor/subjects/{$subjectId}/competencies", ['name' => 'Atomic Structure'])->json('id');
        $ownersClass = $this->actingAs($owner)
            ->postJson("/professor/subjects/{$subjectId}/sections", ['name' => 'Chemistry', 'section' => 'STEM A'])->json('id');

        $this->actingAs($owner)->postJson("/professor/subjects/{$subjectId}/collaborators", ['email' => $first->email])->assertCreated();
        $this->travel(1)->minutes();
        $this->actingAs($owner)->postJson("/professor/subjects/{$subjectId}/collaborators", ['email' => $second->email])->assertCreated();

        // The owner's module, posted by the first collaborator into their own class.
        $moduleId = $this->uploadModule($owner, $subjectId, [$ownersClass]);
        $theirClass = $this->actingAs($first)->postJson('/professor/classes', ['name' => 'Chemistry', 'section' => 'STEM B'])->json();
        $this->actingAs($first)
            ->putJson("/professor/subjects/{$subjectId}/modules/{$moduleId}/sections", ['section_ids' => [$theirClass['id']]])
            ->assertOk();
        $theirPost = ClassPost::where('class_id', $theirClass['id'])->firstOrFail();
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $theirClass['code']])->assertCreated();
        $this->quizWithAttempt($theirPost, $competencyId, $student);

        $this->deleteAccount($owner)->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseMissing('classes', ['id' => $ownersClass]);

        // The subject lives on under the collaborator who joined first.
        $subject = Subject::findOrFail($subjectId);
        $this->assertSame($first->id, $subject->owner_id);
        $this->assertSame([$second->id], $subject->collaborators()->pluck('users.id')->all());
        $this->assertDatabaseHas('subject_collaborators', ['subject_id' => $subjectId, 'user_id' => $second->id, 'invited_by' => $first->id]);
        $this->assertSame($first->id, Module::findOrFail($moduleId)->uploaded_by);
        Storage::disk('local')->assertExists(Module::findOrFail($moduleId)->file_path);

        // The co-teacher's class and its students' results are untouched.
        $this->assertDatabaseHas('classes', ['id' => $theirClass['id'], 'subject_id' => $subjectId]);
        $this->assertDatabaseHas('competencies', ['id' => $competencyId]);
        $this->assertDatabaseHas('quiz_attempts', ['student_id' => $student->id]);
        $this->assertDatabaseHas('student_mastery', ['student_id' => $student->id, 'competency_id' => $competencyId]);
        $this->actingAs($first)->getJson("/professor/subjects/{$subjectId}")->assertOk()->assertJsonPath('modules.0.can_edit', true);
    }

    public function test_a_collaborator_leaving_keeps_their_uploads_in_the_subject(): void
    {
        $owner = $this->professor('Owner');
        $collaborator = $this->professor('Collab');

        $subjectId = $this->actingAs($owner)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $this->actingAs($owner)->postJson("/professor/subjects/{$subjectId}/collaborators", ['email' => $collaborator->email])->assertCreated();

        $ownClass = $this->actingAs($collaborator)->postJson('/professor/classes', ['name' => 'Chemistry'])->json('id');
        $moduleId = $this->uploadModule($collaborator, $subjectId, [$ownClass]);
        $file = Module::findOrFail($moduleId)->file_path;

        $this->deleteAccount($collaborator)->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $collaborator->id]);
        $this->assertDatabaseMissing('classes', ['id' => $ownClass]);
        $this->assertDatabaseMissing('subject_collaborators', ['user_id' => $collaborator->id]);

        // Their upload stays in the owner's subject, now under the owner.
        $this->assertSame($owner->id, Module::findOrFail($moduleId)->uploaded_by);
        $this->assertSame($owner->id, Subject::findOrFail($subjectId)->owner_id);
        Storage::disk('local')->assertExists($file);
        $this->assertSame(0, ClassRoom::count());
    }
}
