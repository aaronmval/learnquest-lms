<?php

namespace Tests\Feature;

use App\Models\ClassRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassRenameTest extends TestCase
{
    use RefreshDatabase;

    private function createClass($professor, array $data = ['name' => 'Chemistry', 'section' => 'STEM 1']): ClassRoom
    {
        $res = $this->actingAs($professor)->postJson('/professor/classes', $data);
        $res->assertCreated();

        return ClassRoom::findOrFail($res->json('id'));
    }

    public function test_owner_can_rename_title_and_section_without_changing_the_code(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $class = $this->createClass($professor);

        $this->actingAs($professor)->putJson("/professor/classes/{$class->id}", [
            'name' => 'General Chemistry 2',
            'section' => 'STEM 4',
        ])
            ->assertOk()
            ->assertJsonPath('name', 'General Chemistry 2')
            ->assertJsonPath('section', 'STEM 4')
            ->assertJsonPath('code', $class->code);

        $this->assertDatabaseHas('classes', [
            'id' => $class->id,
            'name' => 'General Chemistry 2',
            'section' => 'STEM 4',
        ]);
    }

    public function test_owner_can_add_edit_and_clear_subject_and_room(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $class = $this->createClass($professor);

        $this->actingAs($professor)->putJson("/professor/classes/{$class->id}", [
            'name' => 'Chemistry',
            'subject' => 'General Chemistry 2',
            'section' => 'STEM 1',
            'room' => '204',
        ])
            ->assertOk()
            ->assertJsonPath('subject', 'General Chemistry 2')
            ->assertJsonPath('room', '204');

        $this->actingAs($professor)->putJson("/professor/classes/{$class->id}", [
            'name' => 'Chemistry',
            'subject' => null,
            'section' => 'STEM 1',
            'room' => null,
        ])
            ->assertOk()
            ->assertJsonPath('subject', null)
            ->assertJsonPath('room', null);

        $this->actingAs($professor)->putJson("/professor/classes/{$class->id}", [
            'name' => 'Chemistry',
            'room' => str_repeat('1', 41),
        ])->assertUnprocessable()->assertJsonValidationErrors('room');
    }

    public function test_section_can_be_cleared_but_title_is_required(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $class = $this->createClass($professor);

        $this->actingAs($professor)->putJson("/professor/classes/{$class->id}", [
            'name' => 'Chemistry',
            'section' => null,
        ])->assertOk()->assertJsonPath('section', null);

        $this->actingAs($professor)->putJson("/professor/classes/{$class->id}", [
            'name' => '',
            'section' => 'STEM 4',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->actingAs($professor)->putJson("/professor/classes/{$class->id}", [
            'name' => str_repeat('a', 61),
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_other_professors_and_students_cannot_rename(): void
    {
        $owner = User::factory()->create(['role' => 'professor']);
        $other = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);
        $class = $this->createClass($owner);

        $this->actingAs($other)->putJson("/professor/classes/{$class->id}", ['name' => 'Hacked'])
            ->assertNotFound();

        $this->actingAs($student)->putJson("/professor/classes/{$class->id}", ['name' => 'Hacked']);

        $this->assertSame('Chemistry', $class->fresh()->name);
    }
}
