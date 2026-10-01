<?php

namespace Tests\Feature;

use App\Models\ClassRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassStudentsTest extends TestCase
{
    use RefreshDatabase;

    private User $professor;

    private ClassRoom $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professor = User::factory()->create(['role' => 'professor']);
        $classId = $this->actingAs($this->professor)->postJson('/professor/classes', ['name' => 'Chemistry'])->json('id');
        $this->class = ClassRoom::find($classId);
    }

    private function enroll(string $name): User
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name]);
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $this->class->code])->assertCreated();

        return $student;
    }

    public function test_professor_sees_the_enrolled_students_alphabetically(): void
    {
        $bea = $this->enroll('Bea Santos');
        $alice = $this->enroll('Alice Reyes');

        $this->actingAs($this->professor)->getJson("/professor/classes/{$this->class->id}/students")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $alice->id)
            ->assertJsonPath('0.name', 'Alice Reyes')
            ->assertJsonPath('0.email', $alice->email)
            ->assertJsonPath('0.avatar_url', null)
            ->assertJsonPath('1.id', $bea->id)
            ->assertJsonStructure([['id', 'name', 'email', 'avatar_url', 'joined_at']]);
    }

    public function test_removing_a_student_ends_their_access_but_they_can_rejoin(): void
    {
        $alice = $this->enroll('Alice Reyes');
        $bea = $this->enroll('Bea Santos');
        $url = "/professor/classes/{$this->class->id}/students/{$alice->id}";

        $this->actingAs($this->professor)->deleteJson($url)->assertNoContent();

        $this->actingAs($this->professor)->getJson("/professor/classes/{$this->class->id}/students")
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $bea->id);
        $this->actingAs($alice)->getJson("/student/classes/{$this->class->id}")->assertNotFound();
        $this->actingAs($alice)->getJson("/student/classes/{$this->class->id}/posts")->assertNotFound();

        // Removing someone who isn't enrolled is a 404.
        $this->actingAs($this->professor)->deleteJson($url)->assertNotFound();

        $this->actingAs($alice)->postJson('/student/classes/join', ['code' => $this->class->code])->assertCreated();
        $this->actingAs($alice)->getJson("/student/classes/{$this->class->id}")->assertOk();
    }

    public function test_only_the_class_owner_can_list_or_remove_students(): void
    {
        $alice = $this->enroll('Alice Reyes');
        $other = User::factory()->create(['role' => 'professor']);
        $url = "/professor/classes/{$this->class->id}/students";

        $this->actingAs($other)->getJson($url)->assertNotFound();
        $this->actingAs($other)->deleteJson("{$url}/{$alice->id}")->assertNotFound();
        $this->actingAs($alice)->getJson($url)->assertRedirect('/login');

        $this->assertDatabaseHas('class_enrollments', ['class_id' => $this->class->id, 'student_id' => $alice->id]);
    }
}
