<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClassArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function createClass($professor, string $name = 'Homeroom'): int
    {
        $res = $this->actingAs($professor)->postJson('/professor/classes', ['name' => $name]);
        $res->assertCreated();

        return $res->json('id');
    }

    public function test_archiving_removes_a_class_from_the_active_list_and_adds_it_to_archived(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $classId = $this->createClass($professor);

        $this->actingAs($professor)->getJson('/professor/classes')
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingAs($professor)->postJson("/professor/classes/{$classId}/archive")
            ->assertOk();

        $this->actingAs($professor)->getJson('/professor/classes')
            ->assertOk()
            ->assertJsonCount(0);

        $this->actingAs($professor)->getJson('/professor/classes/archived')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $classId);
    }

    public function test_restoring_reverses_archiving(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $classId = $this->createClass($professor);

        $this->actingAs($professor)->postJson("/professor/classes/{$classId}/archive")->assertOk();
        $this->actingAs($professor)->postJson("/professor/classes/{$classId}/restore")->assertOk();

        $this->actingAs($professor)->getJson('/professor/classes')
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingAs($professor)->getJson('/professor/classes/archived')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_deleting_a_non_archived_class_is_rejected(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $classId = $this->createClass($professor);

        $this->actingAs($professor)->deleteJson("/professor/classes/{$classId}")
            ->assertStatus(422);

        $this->assertDatabaseCount('classes', 1);
    }

    public function test_deleting_an_archived_class_succeeds_and_cascades(): void
    {
        Storage::fake('local');

        $professor = User::factory()->create(['role' => 'professor']);
        $classId = $this->createClass($professor);

        $student = User::factory()->create(['role' => 'student']);
        $code = \App\Models\ClassRoom::find($classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        $pdf = UploadedFile::fake()->create('note.pdf', 200, 'application/pdf');
        $post = $this->actingAs($professor)->post("/professor/classes/{$classId}/posts", [
            'type' => 'announcement',
            'quarter' => '1st Quarter',
            'title' => 'Welcome',
            'attachment' => $pdf,
        ]);
        $post->assertCreated();
        $attachmentPath = ClassPost::find($post->json('id'))->attachment_path;

        $this->actingAs($professor)->postJson("/professor/classes/{$classId}/archive")->assertOk();
        $this->actingAs($professor)->deleteJson("/professor/classes/{$classId}")->assertNoContent();

        $this->assertDatabaseCount('classes', 0);
        $this->assertDatabaseCount('class_posts', 0);
        $this->assertDatabaseCount('class_enrollments', 0);
        $this->assertFalse(Storage::disk('local')->exists($attachmentPath));
    }

    public function test_stranger_professor_cannot_archive_restore_or_delete(): void
    {
        $owner = User::factory()->create(['role' => 'professor']);
        $stranger = User::factory()->create(['role' => 'professor']);
        $classId = $this->createClass($owner);

        $this->actingAs($stranger)->postJson("/professor/classes/{$classId}/archive")->assertNotFound();
        $this->actingAs($stranger)->postJson("/professor/classes/{$classId}/restore")->assertNotFound();
        $this->actingAs($stranger)->deleteJson("/professor/classes/{$classId}")->assertNotFound();
    }
}
