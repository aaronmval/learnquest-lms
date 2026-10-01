<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SubjectModuleSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_subject_module_collaborator_flow(): void
    {
        $owner = User::factory()->create(['role' => 'professor']);
        $collaborator = User::factory()->create(['role' => 'professor']);
        $stranger = User::factory()->create(['role' => 'professor']);

        // Create subject
        $res = $this->actingAs($owner)->postJson('/professor/subjects', ['name' => 'Chemistry']);
        $res->assertCreated();
        $subjectId = $res->json('id');

        // Add a section
        $res = $this->actingAs($owner)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Chemistry - STEM 4',
            'section' => 'STEM 4',
        ]);
        $res->assertCreated();
        $sectionId = $res->json('id');
        $this->assertNotEmpty($res->json('code'));

        // Stranger cannot see the subject
        $this->actingAs($stranger)->getJson("/professor/subjects/{$subjectId}")->assertNotFound();

        // Invite collaborator by email
        $res = $this->actingAs($owner)->postJson("/professor/subjects/{$subjectId}/collaborators", [
            'email' => $collaborator->email,
        ]);
        $res->assertCreated();

        // Inviting a non-professor email fails
        $this->actingAs($owner)->postJson("/professor/subjects/{$subjectId}/collaborators", [
            'email' => 'nobody@example.com',
        ])->assertStatus(422);

        // Collaborator can now see and manage the subject
        $res = $this->actingAs($collaborator)->getJson("/professor/subjects/{$subjectId}");
        $res->assertOk();
        $this->assertCount(1, $res->json('sections'));

        // Collaborator uploads a module PDF. The owner's section isn't theirs
        // to post into, so ticking it is ignored.
        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $res = $this->actingAs($collaborator)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Module 1',
            'description' => 'Intro',
            'attachment' => $pdf,
            'section_ids' => [$sectionId],
        ]);
        $res->assertCreated();
        $moduleId = $res->json('id');
        $this->assertSame([], $res->json('target_sections'));

        // Owner can preview/download the collaborator's upload
        $this->actingAs($owner)->get("/professor/subjects/{$subjectId}/modules/{$moduleId}/attachment")
            ->assertOk();

        // Stranger cannot
        $this->actingAs($stranger)->get("/professor/subjects/{$subjectId}/modules/{$moduleId}/attachment")
            ->assertNotFound();

        // Owner edits the module (no new file)
        $res = $this->actingAs($owner)->post("/professor/subjects/{$subjectId}/modules/{$moduleId}", [
            '_method' => 'PUT',
            'title' => 'Module 1 (updated)',
            'description' => 'Intro updated',
        ]);
        $res->assertOk();
        $this->assertSame('Module 1 (updated)', $res->json('title'));

        // Owner removes the collaborator
        $this->actingAs($owner)->deleteJson("/professor/subjects/{$subjectId}/collaborators/{$collaborator->id}")
            ->assertNoContent();

        // Removed collaborator no longer has access
        $this->actingAs($collaborator)->getJson("/professor/subjects/{$subjectId}")->assertNotFound();

        // Owner cannot remove themself
        $this->actingAs($owner)->deleteJson("/professor/subjects/{$subjectId}/collaborators/{$owner->id}")
            ->assertForbidden();

        // Owner deletes the module
        $this->actingAs($owner)->deleteJson("/professor/subjects/{$subjectId}/modules/{$moduleId}")
            ->assertNoContent();

        $this->assertDatabaseCount('modules', 0);
        $this->assertDatabaseCount('module_sections', 0);
    }

    public function test_subject_can_be_renamed_by_owner_but_not_by_a_stranger(): void
    {
        $owner = User::factory()->create(['role' => 'professor']);
        $stranger = User::factory()->create(['role' => 'professor']);

        $res = $this->actingAs($owner)->postJson('/professor/subjects', ['name' => 'Chemistry']);
        $subjectId = $res->json('id');

        $this->actingAs($stranger)->putJson("/professor/subjects/{$subjectId}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $res = $this->actingAs($owner)->putJson("/professor/subjects/{$subjectId}", ['name' => 'Advanced Chemistry']);
        $res->assertOk();
        $this->assertSame('Advanced Chemistry', $res->json('name'));
    }

    public function test_module_can_also_target_professors_own_standalone_class(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);

        // A standalone class, unrelated to any subject.
        $res = $this->actingAs($professor)->postJson('/professor/classes', [
            'name' => 'Homeroom',
        ]);
        $res->assertCreated();
        $classId = $res->json('id');

        // A separate subject the same professor uploads a module under.
        $res = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Biology']);
        $res->assertCreated();
        $subjectId = $res->json('id');

        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $res = $this->actingAs($professor)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Cells',
            'attachment' => $pdf,
            'section_ids' => [$classId],
        ]);
        $res->assertCreated();

        $this->assertSame([$classId], array_map(fn ($s) => $s['id'], $res->json('target_sections')));
    }

    public function test_owner_sees_the_name_of_a_collaborators_own_target_class(): void
    {
        $owner = User::factory()->create(['role' => 'professor']);
        $collaborator = User::factory()->create(['role' => 'professor']);

        $subjectId = $this->actingAs($owner)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $this->actingAs($owner)->postJson("/professor/subjects/{$subjectId}/collaborators", [
            'email' => $collaborator->email,
        ])->assertCreated();

        // The collaborator's own standalone class, which the owner can't list.
        $classId = $this->actingAs($collaborator)->postJson('/professor/classes', [
            'name' => 'Chemistry',
            'section' => 'STEM 7',
        ])->json('id');

        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $this->actingAs($collaborator)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Module 1',
            'attachment' => $pdf,
            'section_ids' => [$classId],
        ])->assertCreated();

        $this->actingAs($owner)->getJson("/professor/subjects/{$subjectId}")
            ->assertOk()
            ->assertJsonPath('modules.0.target_sections.0.id', $classId)
            ->assertJsonPath('modules.0.target_sections.0.name', 'Chemistry')
            ->assertJsonPath('modules.0.target_sections.0.section', 'STEM 7');
    }
}
