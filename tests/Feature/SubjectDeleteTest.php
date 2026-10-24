<?php

namespace Tests\Feature;

use App\Models\ClassRoom;
use App\Models\Competency;
use App\Models\Module;
use App\Models\StudentMastery;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubjectDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $collaborator;

    private int $subjectId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->owner = User::factory()->create(['role' => 'professor']);
        $this->collaborator = User::factory()->create(['role' => 'professor']);

        $this->subjectId = $this->actingAs($this->owner)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $this->actingAs($this->owner)
            ->postJson("/professor/subjects/{$this->subjectId}/collaborators", ['email' => $this->collaborator->email])
            ->assertCreated();
    }

    public function test_index_flags_only_the_owner_as_able_to_delete(): void
    {
        $this->actingAs($this->owner)->getJson('/professor/subjects')->assertJsonPath('0.can_delete', true);
        $this->actingAs($this->collaborator)->getJson('/professor/subjects')->assertJsonPath('0.can_delete', false);
    }

    public function test_owner_deletes_subject_with_its_modules_and_files_but_keeps_sections(): void
    {
        $sectionId = $this->actingAs($this->owner)
            ->postJson("/professor/subjects/{$this->subjectId}/sections", ['name' => 'Chemistry - STEM 4', 'section' => 'STEM 4'])
            ->json('id');
        $moduleId = $this->actingAs($this->owner)->post("/professor/subjects/{$this->subjectId}/modules", [
            'title' => 'Module 1',
            'attachment' => UploadedFile::fake()->create('lesson.pdf', 200, 'application/pdf'),
        ])->assertCreated()->json('id');
        Competency::create(['subject_id' => $this->subjectId, 'name' => 'Stoichiometry']);
        $filePath = Module::find($moduleId)->file_path;
        Storage::disk()->assertExists($filePath);

        $this->actingAs($this->owner)->deleteJson("/professor/subjects/{$this->subjectId}")->assertNoContent();

        $this->assertNull(Subject::find($this->subjectId));
        $this->assertNull(Module::find($moduleId));
        $this->assertSame(0, Competency::where('subject_id', $this->subjectId)->count());
        Storage::disk()->assertMissing($filePath);
        $this->assertNull(ClassRoom::find($sectionId)->subject_id);
    }

    public function test_collaborator_and_stranger_cannot_delete(): void
    {
        $stranger = User::factory()->create(['role' => 'professor']);

        $this->actingAs($this->collaborator)->deleteJson("/professor/subjects/{$this->subjectId}")->assertForbidden();
        $this->actingAs($stranger)->deleteJson("/professor/subjects/{$this->subjectId}")->assertNotFound();

        $this->assertNotNull(Subject::find($this->subjectId));
    }

    public function test_subject_with_student_mastery_data_cannot_be_deleted(): void
    {
        $competency = Competency::create(['subject_id' => $this->subjectId, 'name' => 'Stoichiometry']);
        StudentMastery::create([
            'student_id' => User::factory()->create(['role' => 'student'])->id,
            'competency_id' => $competency->id,
            'initial_mastery' => 0.3,
            'current_mastery' => 0.5,
            'p_l0' => 0.3,
            'p_t' => 0.1,
            'p_g' => 0.2,
            'p_s' => 0.1,
        ]);

        $this->actingAs($this->owner)->deleteJson("/professor/subjects/{$this->subjectId}")->assertStatus(422);

        $this->assertNotNull(Subject::find($this->subjectId));
    }
}
