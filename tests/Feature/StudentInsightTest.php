<?php

namespace Tests\Feature;

use App\Exceptions\AI\LlamaApiException;
use App\Models\ClassRoom;
use App\Models\StudentMastery;
use App\Models\User;
use App\Services\AI\LlamaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StudentInsightTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: int, 2: int, 3: int}  [student, classId, weakCompetencyId, strongCompetencyId]
     */
    private function enrolledStudentWithMastery(bool $withMastery = true): array
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $classId = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Class A',
            'section' => 'STEM A',
        ])->json('id');

        $weakId = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/competencies", [
            'name' => 'Stoichiometry',
        ])->json('id');
        $strongId = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/competencies", [
            'name' => 'Atomic Structure',
        ])->json('id');

        $code = ClassRoom::find($classId)->code;
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $code])->assertCreated();

        if ($withMastery) {
            $this->mastery($student, $weakId, 0.22, 4);
            $this->mastery($student, $strongId, 0.88, 5);
        }

        return [$student, $classId, $weakId, $strongId];
    }

    private function mastery(User $student, int $competencyId, float $current, int $observations): void
    {
        StudentMastery::create([
            'student_id' => $student->id,
            'competency_id' => $competencyId,
            'initial_mastery' => 0.30,
            'current_mastery' => $current,
            'p_l0' => 0.30,
            'p_t' => 0.15,
            'p_g' => 0.25,
            'p_s' => 0.10,
            'observations_count' => $observations,
        ]);
    }

    private function aiReply(int $weakId): array
    {
        return [
            'content' => json_encode(['insights' => [
                ['type' => 'weakness', 'competency_id' => $weakId, 'text' => 'Review mole ratios in Stoichiometry.'],
                ['type' => 'next_step', 'competency_id' => 9999, 'text' => 'Retake the quiz after reviewing.'],
                ['type' => 'bogus', 'competency_id' => null, 'text' => 'Dropped.'],
            ]]),
            'model' => 'llama-3.3-70b-instruct',
        ];
    }

    public function test_unenrolled_student_gets_404(): void
    {
        [, $classId] = $this->enrolledStudentWithMastery(false);
        $outsider = User::factory()->create(['role' => 'student']);

        $this->actingAs($outsider)->getJson("/student/classes/{$classId}/insights")->assertNotFound();
    }

    public function test_no_assessed_competencies_returns_empty_without_calling_ai(): void
    {
        [$student, $classId] = $this->enrolledStudentWithMastery(false);

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldNotReceive('chat');
        $this->app->instance(LlamaService::class, $llama);

        $res = $this->actingAs($student)->getJson("/student/classes/{$classId}/insights");
        $res->assertOk();
        $res->assertJsonPath('source', 'empty');
        $res->assertJsonPath('insights', []);
    }

    public function test_returns_ai_insights_with_bkt_weaknesses_and_caches_them(): void
    {
        [$student, $classId, $weakId, $strongId] = $this->enrolledStudentWithMastery();

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->once()->andReturn($this->aiReply($weakId));
        $this->app->instance(LlamaService::class, $llama);

        $res = $this->actingAs($student)->getJson("/student/classes/{$classId}/insights");
        $res->assertOk();
        $res->assertJsonPath('source', 'ai');
        $res->assertJsonPath('overall.level', 'developing');
        $res->assertJsonPath('weaknesses.0.id', $weakId);
        $res->assertJsonPath('weaknesses.0.level', 'low');
        $res->assertJsonPath('strengths.0.id', $strongId);
        $res->assertJsonCount(2, 'insights');
        $res->assertJsonPath('insights.0.competency_name', 'Stoichiometry');
        // Unknown competency id from the model is not trusted.
        $res->assertJsonPath('insights.1.competency_name', null);

        // Served from cache — chat() is only expected once.
        $this->actingAs($student)->getJson("/student/classes/{$classId}/insights")
            ->assertOk()->assertJsonPath('source', 'ai');
    }

    public function test_refresh_regenerates_insights(): void
    {
        [$student, $classId, $weakId] = $this->enrolledStudentWithMastery();

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->twice()->andReturn($this->aiReply($weakId));
        $this->app->instance(LlamaService::class, $llama);

        $this->actingAs($student)->getJson("/student/classes/{$classId}/insights")->assertOk();
        $this->actingAs($student)->getJson("/student/classes/{$classId}/insights?refresh=1")->assertOk();
    }

    public function test_falls_back_to_rule_based_insights_when_ai_fails(): void
    {
        [$student, $classId] = $this->enrolledStudentWithMastery();

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->andThrow(new LlamaApiException('down'));
        $this->app->instance(LlamaService::class, $llama);

        $res = $this->actingAs($student)->getJson("/student/classes/{$classId}/insights");
        $res->assertOk();
        $res->assertJsonPath('source', 'rules');
        $res->assertJsonPath('insights.0.type', 'weakness');
        $res->assertJsonPath('insights.0.competency_name', 'Stoichiometry');
    }

    public function test_falls_back_when_ai_returns_malformed_json(): void
    {
        [$student, $classId] = $this->enrolledStudentWithMastery();

        $llama = Mockery::mock(LlamaService::class);
        $llama->shouldReceive('chat')->andReturn(['content' => 'not json at all', 'model' => 'llama']);
        $this->app->instance(LlamaService::class, $llama);

        $this->actingAs($student)->getJson("/student/classes/{$classId}/insights")
            ->assertOk()
            ->assertJsonPath('source', 'rules');
    }
}
