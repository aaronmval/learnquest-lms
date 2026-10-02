<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModuleClassPostTest extends TestCase
{
    use RefreshDatabase;

    private function createClass($professor, string $name = 'Homeroom'): int
    {
        $res = $this->actingAs($professor)->postJson('/professor/classes', ['name' => $name]);
        $res->assertCreated();

        return $res->json('id');
    }

    public function test_uploading_a_module_with_checked_classes_creates_lesson_posts(): void
    {
        Storage::fake('local');

        $professor = User::factory()->create(['role' => 'professor']);
        $classA = $this->createClass($professor, 'Class A');
        $classB = $this->createClass($professor, 'Class B');

        $subjectRes = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Biology']);
        $subjectId = $subjectRes->json('id');

        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $res = $this->actingAs($professor)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Cells',
            'description' => 'All about cells',
            'attachment' => $pdf,
            'section_ids' => [$classA, $classB],
            'quarter' => '2nd Quarter',
        ]);
        $res->assertCreated();
        $moduleId = $res->json('id');

        $this->assertDatabaseCount('class_posts', 2);

        $postA = ClassPost::where('class_id', $classA)->first();
        $this->assertNotNull($postA);
        $this->assertSame($moduleId, $postA->module_id);
        $this->assertSame('lesson', $postA->type);
        $this->assertSame('2nd Quarter', $postA->quarter);
        $this->assertSame('Cells', $postA->title);
        $this->assertSame('All about cells', $postA->body);
        $this->assertNotNull($postA->attachment_path);
        $this->assertTrue(Storage::disk('local')->exists($postA->attachment_path));
    }

    public function test_resaving_with_the_same_classes_does_not_duplicate_posts(): void
    {
        Storage::fake('local');

        $professor = User::factory()->create(['role' => 'professor']);
        $classA = $this->createClass($professor, 'Class A');

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Biology'])->json('id');

        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $res = $this->actingAs($professor)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Cells',
            'attachment' => $pdf,
            'section_ids' => [$classA],
        ]);
        $moduleId = $res->json('id');

        $this->actingAs($professor)->post("/professor/subjects/{$subjectId}/modules/{$moduleId}", [
            '_method' => 'PUT',
            'title' => 'Cells',
            'section_ids' => [$classA],
        ])->assertOk();

        $this->assertDatabaseCount('class_posts', 1);
    }

    public function test_adding_a_new_class_on_edit_creates_exactly_one_new_post(): void
    {
        Storage::fake('local');

        $professor = User::factory()->create(['role' => 'professor']);
        $classA = $this->createClass($professor, 'Class A');
        $classB = $this->createClass($professor, 'Class B');

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Biology'])->json('id');

        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $res = $this->actingAs($professor)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Cells',
            'attachment' => $pdf,
            'section_ids' => [$classA],
        ]);
        $moduleId = $res->json('id');
        $originalPostId = ClassPost::where('class_id', $classA)->first()->id;

        $this->actingAs($professor)->post("/professor/subjects/{$subjectId}/modules/{$moduleId}", [
            '_method' => 'PUT',
            'title' => 'Cells',
            'section_ids' => [$classA, $classB],
        ])->assertOk();

        $this->assertDatabaseCount('class_posts', 2);
        $this->assertSame($originalPostId, ClassPost::where('class_id', $classA)->first()->id);
        $this->assertNotNull(ClassPost::where('class_id', $classB)->first());
    }

    public function test_unchecking_a_class_does_not_delete_its_post(): void
    {
        Storage::fake('local');

        $professor = User::factory()->create(['role' => 'professor']);
        $classA = $this->createClass($professor, 'Class A');

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Biology'])->json('id');

        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $res = $this->actingAs($professor)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Cells',
            'attachment' => $pdf,
            'section_ids' => [$classA],
        ]);
        $moduleId = $res->json('id');

        $this->actingAs($professor)->post("/professor/subjects/{$subjectId}/modules/{$moduleId}", [
            '_method' => 'PUT',
            'title' => 'Cells',
            'section_ids' => [],
        ])->assertOk();

        $this->assertDatabaseCount('class_posts', 1);
    }

    public function test_empty_section_ids_creates_no_posts(): void
    {
        Storage::fake('local');

        $professor = User::factory()->create(['role' => 'professor']);
        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Biology'])->json('id');

        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $this->actingAs($professor)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Cells',
            'attachment' => $pdf,
        ])->assertCreated();

        $this->assertDatabaseCount('class_posts', 0);
    }

    public function test_deleting_a_module_nulls_module_id_on_its_posts_without_deleting_them(): void
    {
        Storage::fake('local');

        $professor = User::factory()->create(['role' => 'professor']);
        $classA = $this->createClass($professor, 'Class A');

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Biology'])->json('id');

        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $moduleId = $this->actingAs($professor)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Cells',
            'attachment' => $pdf,
            'section_ids' => [$classA],
        ])->json('id');

        $post = ClassPost::where('class_id', $classA)->first();
        $attachmentPath = $post->attachment_path;

        $this->actingAs($professor)->deleteJson("/professor/subjects/{$subjectId}/modules/{$moduleId}")
            ->assertNoContent();

        $post->refresh();
        $this->assertNull($post->module_id);
        $this->assertTrue(Storage::disk('local')->exists($attachmentPath));
    }

    public function test_enrolled_student_can_see_post_and_attachment_but_non_enrolled_cannot(): void
    {
        Storage::fake('local');

        $professor = User::factory()->create(['role' => 'professor']);
        $enrolledStudent = User::factory()->create(['role' => 'student']);
        $otherStudent = User::factory()->create(['role' => 'student']);

        $classA = $this->createClass($professor, 'Class A');
        $classCode = \App\Models\ClassRoom::find($classA)->code;
        $this->actingAs($enrolledStudent)->postJson('/student/classes/join', ['code' => $classCode])
            ->assertCreated();

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Biology'])->json('id');

        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $this->actingAs($professor)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Cells',
            'attachment' => $pdf,
            'section_ids' => [$classA],
        ])->assertCreated();

        $postId = ClassPost::where('class_id', $classA)->first()->id;

        $res = $this->actingAs($enrolledStudent)->getJson("/student/classes/{$classA}/posts");
        $res->assertOk();
        $this->assertCount(1, $res->json());

        $this->actingAs($enrolledStudent)->get("/student/classes/{$classA}/posts/{$postId}/attachment")
            ->assertOk();

        $this->actingAs($otherStudent)->getJson("/student/classes/{$classA}/posts")->assertNotFound();
        $this->actingAs($otherStudent)->get("/student/classes/{$classA}/posts/{$postId}/attachment")
            ->assertNotFound();
    }

    public function test_collaborator_can_only_post_a_module_into_their_own_sections(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create(['role' => 'professor']);
        $collaborator = User::factory()->create(['role' => 'professor']);

        $subjectRes = $this->actingAs($owner)->postJson('/professor/subjects', ['name' => 'Chemistry']);
        $subjectId = $subjectRes->json('id');

        $sectionRes = $this->actingAs($owner)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Chemistry - STEM 4',
            'section' => 'STEM 4',
        ]);
        $sectionId = $sectionRes->json('id');

        $this->actingAs($owner)->postJson("/professor/subjects/{$subjectId}/collaborators", [
            'email' => $collaborator->email,
        ])->assertCreated();

        $ownClass = $this->createClass($collaborator, 'Chemistry - STEM 7');
        $otherSubjectId = $this->actingAs($collaborator)->postJson('/professor/subjects', ['name' => 'Physics'])->json('id');
        $otherSubjectClass = $this->actingAs($collaborator)
            ->postJson("/professor/subjects/{$otherSubjectId}/sections", ['name' => 'Physics - STEM 7', 'section' => 'STEM 7'])->json('id');

        // The upload dialog offers each professor only what they may tick.
        $this->assertSame(
            [$ownClass],
            array_column($this->actingAs($collaborator)->getJson("/professor/subjects/{$subjectId}")->json('targetable_sections'), 'id'),
        );
        $this->assertSame(
            [$sectionId],
            array_column($this->actingAs($owner)->getJson("/professor/subjects/{$subjectId}")->json('targetable_sections'), 'id'),
        );

        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $res = $this->actingAs($collaborator)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Module 1',
            'attachment' => $pdf,
            'section_ids' => [$sectionId, $ownClass, $otherSubjectClass],
        ]);
        $res->assertCreated();
        $moduleId = $res->json('id');

        // Only the collaborator's own class is targeted, and it joins the subject.
        $this->assertSame([$ownClass], array_column($res->json('target_sections'), 'id'));
        $this->assertNull(ClassPost::where('class_id', $sectionId)->first());
        $this->assertNull(ClassPost::where('class_id', $otherSubjectClass)->first());
        $this->assertSame($collaborator->id, ClassPost::where('class_id', $ownClass)->first()->author_id);
        $this->assertDatabaseHas('classes', ['id' => $ownClass, 'subject_id' => $subjectId]);
        $this->assertDatabaseHas('classes', ['id' => $otherSubjectClass, 'subject_id' => $otherSubjectId]);

        // The owner adds their section without dropping the collaborator's,
        // and the collaborator can't remove the owner's.
        $res = $this->actingAs($owner)->post("/professor/subjects/{$subjectId}/modules/{$moduleId}", [
            '_method' => 'PUT',
            'title' => 'Module 1',
            'section_ids' => [$sectionId, $ownClass],
        ])->assertOk();
        $this->assertEqualsCanonicalizing([$sectionId, $ownClass], array_column($res->json('target_sections'), 'id'));

        $res = $this->actingAs($collaborator)->post("/professor/subjects/{$subjectId}/modules/{$moduleId}", [
            '_method' => 'PUT',
            'title' => 'Module 1',
        ])->assertOk();
        $this->assertSame([$sectionId], array_column($res->json('target_sections'), 'id'));

        // The collaborator's class is now a section of the subject, but the
        // owner still sees, counts and posts into only their own section.
        $ownerView = $this->actingAs($owner)->getJson("/professor/subjects/{$subjectId}")->assertOk();
        $this->assertSame([$sectionId], array_column($ownerView->json('sections'), 'id'));
        $this->assertSame([$sectionId], array_column($ownerView->json('targetable_sections'), 'id'));
        $this->assertSame(1, $this->actingAs($owner)->getJson('/professor/subjects')->json('0.sections_count'));
        $this->assertSame([$ownClass], array_column(
            $this->actingAs($collaborator)->getJson("/professor/subjects/{$subjectId}")->json('sections'), 'id',
        ));

        $res = $this->actingAs($owner)->post("/professor/subjects/{$subjectId}/modules/{$moduleId}", [
            '_method' => 'PUT',
            'title' => 'Module 1',
            'section_ids' => [$sectionId, $ownClass],
        ])->assertOk();
        $this->assertSame([$sectionId], array_column($res->json('target_sections'), 'id'));
    }

    public function test_backfill_links_standalone_classes_that_already_have_module_lessons(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $classA = $this->createClass($professor, 'Class A');
        $mixed = $this->createClass($professor, 'Mixed');
        $biology = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Biology'])->json('id');
        $physics = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Physics'])->json('id');

        $module = fn (int $subjectId) => \App\Models\Module::create([
            'subject_id' => $subjectId, 'uploaded_by' => $professor->id, 'title' => 'M',
            'file_path' => 'x.pdf', 'file_name' => 'x.pdf', 'file_size' => 1,
        ])->id;
        $post = fn (int $classId, int $moduleId) => ClassPost::create([
            'class_id' => $classId, 'author_id' => $professor->id, 'module_id' => $moduleId,
            'type' => 'lesson', 'quarter' => '1st Quarter', 'title' => 'M',
        ]);

        $post($classA, $module($biology));
        $post($mixed, $module($biology));
        $post($mixed, $module($physics));

        (require database_path('migrations/2026_10_02_000001_link_module_target_classes_to_subject.php'))->up();

        $this->assertDatabaseHas('classes', ['id' => $classA, 'subject_id' => $biology]);
        // Lessons from two subjects: left alone.
        $this->assertDatabaseHas('classes', ['id' => $mixed, 'subject_id' => null]);
    }

    public function test_quarter_defaults_to_1st_quarter_when_omitted(): void
    {
        Storage::fake('local');

        $professor = User::factory()->create(['role' => 'professor']);
        $classA = $this->createClass($professor, 'Class A');

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Biology'])->json('id');

        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $this->actingAs($professor)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Cells',
            'attachment' => $pdf,
            'section_ids' => [$classA],
        ])->assertCreated();

        $this->assertSame('1st Quarter', ClassPost::where('class_id', $classA)->first()->quarter);
    }

    public function test_quarter_is_saved_on_the_module_and_reused_for_later_posts(): void
    {
        Storage::fake('local');

        $professor = User::factory()->create(['role' => 'professor']);
        $classA = $this->createClass($professor, 'Class A');

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Biology'])->json('id');

        $pdf = UploadedFile::fake()->create('lesson.pdf', 500, 'application/pdf');
        $res = $this->actingAs($professor)->post("/professor/subjects/{$subjectId}/modules", [
            'title' => 'Cells',
            'attachment' => $pdf,
            'quarter' => '3rd Quarter',
        ]);
        $res->assertCreated()->assertJsonPath('quarter', '3rd Quarter');
        $moduleId = $res->json('id');

        $this->actingAs($professor)->getJson("/professor/subjects/{$subjectId}")
            ->assertOk()
            ->assertJsonPath('modules.0.quarter', '3rd Quarter');

        // Assigning a class later, without resending the quarter, keeps it.
        $this->actingAs($professor)->post("/professor/subjects/{$subjectId}/modules/{$moduleId}", [
            '_method' => 'PUT',
            'title' => 'Cells',
            'section_ids' => [$classA],
        ])->assertOk()->assertJsonPath('quarter', '3rd Quarter');

        $this->assertSame('3rd Quarter', ClassPost::where('class_id', $classA)->first()->quarter);
    }
}
