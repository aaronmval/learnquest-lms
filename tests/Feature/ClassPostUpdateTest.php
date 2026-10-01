<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClassPostUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function createClass($professor): int
    {
        return $this->actingAs($professor)->postJson('/professor/classes', ['name' => 'Chemistry'])->json('id');
    }

    public function test_lesson_can_be_edited_the_way_the_class_page_sends_it(): void
    {
        Storage::fake('local');

        $professor = User::factory()->create(['role' => 'professor']);
        $classId = $this->createClass($professor);

        $postId = $this->actingAs($professor)->post("/professor/classes/{$classId}/posts", [
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'SLM 1',
            'body' => 'Original',
            'attachment' => UploadedFile::fake()->create('slm1.pdf', 200, 'application/pdf'),
        ])->assertCreated()->json('id');

        $originalPath = ClassPost::find($postId)->attachment_path;

        // Multipart POST with _method=PUT and no new file, as the composer sends.
        $this->actingAs($professor)->post("/professor/classes/{$classId}/posts/{$postId}", [
            '_method' => 'PUT',
            'type' => 'lesson',
            'quarter' => '3rd Quarter',
            'title' => 'SLM 1: Intermolecular Forces',
            'body' => 'Updated',
        ])
            ->assertOk()
            ->assertJsonPath('title', 'SLM 1: Intermolecular Forces')
            ->assertJsonPath('quarter', '3rd Quarter')
            ->assertJsonPath('edited', true);

        $post = ClassPost::find($postId);
        $this->assertSame('Updated', $post->body);
        $this->assertSame($originalPath, $post->attachment_path);
        $this->assertTrue(Storage::disk('local')->exists($originalPath));
    }

    public function test_editing_a_lesson_can_replace_its_pdf(): void
    {
        Storage::fake('local');

        $professor = User::factory()->create(['role' => 'professor']);
        $classId = $this->createClass($professor);

        $postId = $this->actingAs($professor)->post("/professor/classes/{$classId}/posts", [
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'SLM 1',
            'attachment' => UploadedFile::fake()->create('old.pdf', 200, 'application/pdf'),
        ])->json('id');

        $oldPath = ClassPost::find($postId)->attachment_path;

        $this->actingAs($professor)->post("/professor/classes/{$classId}/posts/{$postId}", [
            '_method' => 'PUT',
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'SLM 1',
            'attachment' => UploadedFile::fake()->create('new.pdf', 200, 'application/pdf'),
        ])->assertOk()->assertJsonPath('attachment_name', 'new.pdf');

        $this->assertFalse(Storage::disk('local')->exists($oldPath));
        $this->assertTrue(Storage::disk('local')->exists(ClassPost::find($postId)->attachment_path));
    }

    public function test_another_professor_cannot_edit_the_post(): void
    {
        $owner = User::factory()->create(['role' => 'professor']);
        $other = User::factory()->create(['role' => 'professor']);
        $classId = $this->createClass($owner);

        $postId = $this->actingAs($owner)->post("/professor/classes/{$classId}/posts", [
            'type' => 'announcement',
            'quarter' => '1st Quarter',
            'title' => 'Welcome',
        ])->json('id');

        $this->actingAs($other)->post("/professor/classes/{$classId}/posts/{$postId}", [
            '_method' => 'PUT',
            'type' => 'announcement',
            'quarter' => '1st Quarter',
            'title' => 'Hacked',
        ])->assertNotFound();

        $this->assertSame('Welcome', ClassPost::find($postId)->title);
    }
}
