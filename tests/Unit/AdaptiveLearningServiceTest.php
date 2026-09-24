<?php

namespace Tests\Unit;

use App\Models\StudentMastery;
use App\Models\User;
use App\Services\Learning\AdaptiveLearningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdaptiveLearningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_classify_uses_configured_thresholds(): void
    {
        config(['bkt.mastery_threshold_low' => 0.40, 'bkt.mastery_threshold_high' => 0.75]);
        $service = new AdaptiveLearningService();

        $this->assertSame('low', $service->classify(0.39));
        $this->assertSame('developing', $service->classify(0.40));
        $this->assertSame('developing', $service->classify(0.749));
        $this->assertSame('high', $service->classify(0.75));
    }

    public function test_profile_weaknesses_and_strengths_ignore_unassessed_competencies(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);
        $subject = $professor->ownedSubjects()->create(['name' => 'Chemistry']);

        $atoms = $subject->competencies()->create(['name' => 'Atomic Structure']);
        $bonds = $subject->competencies()->create(['name' => 'Chemical Bonding']);
        $moles = $subject->competencies()->create(['name' => 'Stoichiometry']);
        $gases = $subject->competencies()->create(['name' => 'Gas Laws']);

        $this->mastery($student, $atoms->id, 0.55, 3);
        $this->mastery($student, $bonds->id, 0.20, 4);
        $this->mastery($student, $moles->id, 0.90, 5);
        // $gases has no record: unassessed.

        $service = new AdaptiveLearningService();
        $profile = $service->competencyProfile($student, $subject->competencies()->get());

        $this->assertCount(4, $profile);
        $gasesRow = collect($profile)->firstWhere('id', $gases->id);
        $this->assertFalse($gasesRow['assessed']);

        $weaknesses = $service->weaknesses($profile);
        $this->assertSame([$bonds->id, $atoms->id], array_column($weaknesses, 'id'));
        $this->assertSame('low', $weaknesses[0]['level']);

        $strengths = $service->strengths($profile);
        $this->assertSame([$moles->id], array_column($strengths, 'id'));
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
}
