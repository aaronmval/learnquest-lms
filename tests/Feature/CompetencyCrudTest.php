<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetencyCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_owning_professor_can_crud_competencies(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');

        $created = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/competencies", [
            'name' => 'Atomic Structure',
            'description' => 'Protons, neutrons, electrons.',
        ]);
        $created->assertCreated();
        $competencyId = $created->json('id');

        $this->actingAs($professor)->getJson("/professor/subjects/{$subjectId}/competencies")
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingAs($professor)->putJson("/professor/subjects/{$subjectId}/competencies/{$competencyId}", [
            'name' => 'Atomic Structure & Bonding',
        ])->assertOk()->assertJsonPath('name', 'Atomic Structure & Bonding');

        $this->actingAs($professor)->deleteJson("/professor/subjects/{$subjectId}/competencies/{$competencyId}")
            ->assertNoContent();

        $this->assertDatabaseCount('competencies', 0);
    }

    public function test_non_owning_professor_gets_404(): void
    {
        $owner = User::factory()->create(['role' => 'professor']);
        $outsider = User::factory()->create(['role' => 'professor']);

        $subjectId = $this->actingAs($owner)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $competencyId = $this->actingAs($owner)->postJson("/professor/subjects/{$subjectId}/competencies", [
            'name' => 'Atomic Structure',
        ])->json('id');

        $this->actingAs($outsider)->getJson("/professor/subjects/{$subjectId}/competencies")->assertNotFound();
        $this->actingAs($outsider)->postJson("/professor/subjects/{$subjectId}/competencies", ['name' => 'X'])->assertNotFound();
        $this->actingAs($outsider)->putJson("/professor/subjects/{$subjectId}/competencies/{$competencyId}", ['name' => 'X'])->assertNotFound();
        $this->actingAs($outsider)->deleteJson("/professor/subjects/{$subjectId}/competencies/{$competencyId}")->assertNotFound();
    }

    public function test_deleting_an_in_use_competency_is_refused(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $classId = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Class A',
            'section' => 'STEM A',
        ])->json('id');
        $competencyId = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/competencies", [
            'name' => 'Atomic Structure',
        ])->json('id');

        $post = ClassPost::create([
            'class_id' => $classId,
            'author_id' => $professor->id,
            'type' => 'lesson',
            'quarter' => '1st Quarter',
            'title' => 'Lesson',
        ]);
        $quiz = Quiz::create([
            'class_post_id' => $post->id,
            'generated_at' => now(),
        ]);
        $quiz->questions()->create([
            'competency_id' => $competencyId,
            'question_text' => 'Q',
            'choices' => ['A', 'B'],
            'correct_answer' => 'A',
            'explanation' => 'Because A.',
            'difficulty' => 'easy',
        ]);

        $this->actingAs($professor)->deleteJson("/professor/subjects/{$subjectId}/competencies/{$competencyId}")
            ->assertStatus(422);

        $this->assertDatabaseCount('competencies', 1);
    }
}
