<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModulePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $collaborator;

    private int $subjectId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->owner = User::factory()->create(['role' => 'professor', 'name' => 'Olivia Owner']);
        $this->collaborator = User::factory()->create(['role' => 'professor', 'name' => 'Carl Collab']);

        $this->subjectId = $this->actingAs($this->owner)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $this->actingAs($this->owner)
            ->postJson("/professor/subjects/{$this->subjectId}/collaborators", ['email' => $this->collaborator->email])
            ->assertCreated();
    }

    private function upload(User $professor, string $title): int
    {
        return $this->actingAs($professor)->post("/professor/subjects/{$this->subjectId}/modules", [
            'title' => $title,
            'attachment' => UploadedFile::fake()->create('lesson.pdf', 200, 'application/pdf'),
        ])->assertCreated()->json('id');
    }

    private function moduleUrl(int $moduleId, string $suffix = ''): string
    {
        return "/professor/subjects/{$this->subjectId}/modules/{$moduleId}{$suffix}";
    }

    public function test_collaborator_cannot_edit_or_delete_a_module_that_is_not_theirs(): void
    {
        $ownersModule = $this->upload($this->owner, 'Atoms');

        $this->actingAs($this->collaborator)
            ->post($this->moduleUrl($ownersModule), ['_method' => 'PUT', 'title' => 'Hijacked'], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->actingAs($this->collaborator)->deleteJson($this->moduleUrl($ownersModule))->assertForbidden();

        $this->assertSame('Atoms', Module::findOrFail($ownersModule)->title);

        // The page is told which modules this professor may change.
        $canEdit = fn (User $user) => collect(
            $this->actingAs($user)->getJson("/professor/subjects/{$this->subjectId}")->assertOk()->json('modules')
        )->pluck('can_edit', 'id');
        $this->assertFalse($canEdit($this->collaborator)[$ownersModule]);
        $this->assertTrue($canEdit($this->owner)[$ownersModule]);
        $this->assertFalse($this->actingAs($this->collaborator)
            ->getJson("/professor/subjects/{$this->subjectId}/modules")->json('0.can_edit'));
    }

    public function test_uploader_and_owner_can_edit_and_delete(): void
    {
        $theirModule = $this->upload($this->collaborator, 'Bonding');

        // The collaborator manages their own upload...
        $this->actingAs($this->collaborator)
            ->post($this->moduleUrl($theirModule), ['_method' => 'PUT', 'title' => 'Bonding v2'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('title', 'Bonding v2')
            ->assertJsonPath('can_edit', true);

        // ...and so does the subject's owner.
        $this->actingAs($this->owner)
            ->post($this->moduleUrl($theirModule), ['_method' => 'PUT', 'title' => 'Bonding v3'], ['Accept' => 'application/json'])
            ->assertOk();
        $this->actingAs($this->owner)->deleteJson($this->moduleUrl($theirModule))->assertNoContent();

        $again = $this->upload($this->collaborator, 'Moles');
        $this->actingAs($this->collaborator)->deleteJson($this->moduleUrl($again))->assertNoContent();
        $this->assertDatabaseCount('modules', 0);
    }

    public function test_collaborator_can_still_post_the_owners_module_to_their_own_class(): void
    {
        $ownersModule = $this->upload($this->owner, 'Atoms');
        $ownClass = $this->actingAs($this->collaborator)->postJson('/professor/classes', ['name' => 'Chemistry'])->json('id');

        $this->actingAs($this->collaborator)
            ->putJson($this->moduleUrl($ownersModule, '/sections'), ['section_ids' => [$ownClass]])
            ->assertOk()
            ->assertJsonPath('title', 'Atoms')
            ->assertJsonPath('can_edit', false)
            ->assertJsonPath('target_sections.0.id', $ownClass);

        $post = ClassPost::where('class_id', $ownClass)->firstOrFail();
        $this->assertSame($ownersModule, $post->module_id);
        $this->assertSame($this->collaborator->id, $post->author_id);

        $stranger = User::factory()->create(['role' => 'professor']);
        $this->actingAs($stranger)->putJson($this->moduleUrl($ownersModule, '/sections'), ['section_ids' => []])->assertNotFound();
    }

    public function test_change_request_alerts_the_subject_owner(): void
    {
        $ownersModule = $this->upload($this->owner, 'Atoms');
        $url = $this->moduleUrl($ownersModule, '/change-requests');

        $this->actingAs($this->collaborator)
            ->postJson($url, ['action' => 'delete', 'note' => 'This is the old edition.'])
            ->assertCreated();

        $alerts = $this->owner->fresh()->notifications;
        $this->assertCount(1, $alerts);
        $this->assertSame('module_change_requested', $alerts[0]->data['kind']);
        $this->assertSame('Delete requested', $alerts[0]->data['title']);
        $this->assertSame(
            'Carl Collab asks you to delete "Atoms" in Chemistry: This is the old edition.',
            $alerts[0]->data['message'],
        );
        $this->assertSame("/pages/professor/professor-module-view.html?subject={$this->subjectId}", $alerts[0]->data['url']);
        $this->assertCount(0, $this->collaborator->fresh()->notifications);

        // The module is untouched: the owner decides.
        $this->assertDatabaseHas('modules', ['id' => $ownersModule, 'title' => 'Atoms']);

        $this->actingAs($this->collaborator)->postJson($url, ['action' => 'edit'])
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $this->actingAs($this->collaborator)->postJson($url, ['action' => 'rename', 'note' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('action');

        // Someone who can change it themselves has nothing to request.
        $this->actingAs($this->owner)->postJson($url, ['action' => 'edit', 'note' => 'x'])->assertStatus(422);
        $theirModule = $this->upload($this->collaborator, 'Bonding');
        $this->actingAs($this->collaborator)
            ->postJson($this->moduleUrl($theirModule, '/change-requests'), ['action' => 'edit', 'note' => 'x'])
            ->assertStatus(422);

        $stranger = User::factory()->create(['role' => 'professor']);
        $this->actingAs($stranger)->postJson($url, ['action' => 'edit', 'note' => 'x'])->assertNotFound();

        $this->assertCount(1, $this->owner->fresh()->notifications);
    }
}
