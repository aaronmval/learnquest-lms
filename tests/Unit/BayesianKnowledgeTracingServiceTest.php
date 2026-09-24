<?php

namespace Tests\Unit;

use App\Models\Competency;
use App\Models\StudentMastery;
use App\Models\User;
use App\Services\Bkt\BayesianKnowledgeTracingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BayesianKnowledgeTracingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompetency(): Competency
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $subject = $professor->ownedSubjects()->create(['name' => 'Chemistry']);

        return $subject->competencies()->create([
            'name' => 'Atomic Structure',
            'description' => 'Protons, neutrons, electrons.',
        ]);
    }

    private function makeStudent(): User
    {
        return User::factory()->create(['role' => 'student']);
    }

    public function test_initialize_mastery_seeds_defaults(): void
    {
        $bkt = new BayesianKnowledgeTracingService;
        $student = $this->makeStudent();
        $competency = $this->makeCompetency();

        $mastery = $bkt->initializeMastery($student, $competency);

        $this->assertSame((float) config('bkt.default_pl0'), $mastery->current_mastery);
        $this->assertSame((float) config('bkt.default_pl0'), $mastery->initial_mastery);
        $this->assertSame((float) config('bkt.default_pt'), $mastery->p_t);
        $this->assertSame((float) config('bkt.default_pg'), $mastery->p_g);
        $this->assertSame((float) config('bkt.default_ps'), $mastery->p_s);
        $this->assertSame(0, $mastery->observations_count);
    }

    public function test_get_mastery_is_idempotent(): void
    {
        $bkt = new BayesianKnowledgeTracingService;
        $student = $this->makeStudent();
        $competency = $this->makeCompetency();

        $first = $bkt->getMastery($student, $competency);
        $first->update(['current_mastery' => 0.9]);

        $second = $bkt->getMastery($student, $competency);

        $this->assertSame(1, StudentMastery::count());
        $this->assertEqualsWithDelta(0.9, $second->current_mastery, 0.0001);
    }

    public function test_correct_response_matches_hand_computed_posterior(): void
    {
        $bkt = new BayesianKnowledgeTracingService;
        $student = $this->makeStudent();
        $competency = $this->makeCompetency();

        // priorL=0.30, pT=0.15, pG=0.25, pS=0.10 (defaults):
        // numerator = 0.30*(1-0.10) = 0.27
        // denominator = 0.27 + (1-0.30)*0.25 = 0.445
        // posterior = 0.27/0.445 = 0.6067415730337079
        // updatedL = posterior + (1-posterior)*0.15 = 0.6657303370786517
        $mastery = $bkt->updateAfterCorrectResponse($student, $competency);

        $this->assertEqualsWithDelta(0.6657303370786517, $mastery->current_mastery, 1e-9);
        $this->assertSame(1, $mastery->observations_count);
        $this->assertSame(1, $mastery->correct_count);
        $this->assertSame(0, $mastery->incorrect_count);
        $this->assertSame('correct', $mastery->last_response);
        $this->assertNotNull($mastery->last_updated_at);
    }

    public function test_incorrect_response_matches_hand_computed_posterior(): void
    {
        $bkt = new BayesianKnowledgeTracingService;
        $student = $this->makeStudent();
        $competency = $this->makeCompetency();

        // priorL=0.30, pT=0.15, pG=0.25, pS=0.10 (defaults):
        // numerator = 0.30*0.10 = 0.03
        // denominator = 0.03 + (1-0.30)*(1-0.25) = 0.555
        // posterior = 0.03/0.555 = 0.05405405405405405
        // updatedL = posterior + (1-posterior)*0.15 = 0.19594594594594594
        $mastery = $bkt->updateAfterIncorrectResponse($student, $competency);

        $this->assertEqualsWithDelta(0.19594594594594594, $mastery->current_mastery, 1e-9);
        // Mastery never collapses to exactly zero: the learning-transition
        // term (pT) always adds some probability even after a wrong answer.
        $this->assertGreaterThan(0.0, $mastery->current_mastery);
        $this->assertSame(1, $mastery->observations_count);
        $this->assertSame(0, $mastery->correct_count);
        $this->assertSame(1, $mastery->incorrect_count);
        $this->assertSame('incorrect', $mastery->last_response);
    }

    public function test_repeated_correct_responses_increase_and_stay_bounded(): void
    {
        $bkt = new BayesianKnowledgeTracingService;
        $student = $this->makeStudent();
        $competency = $this->makeCompetency();

        $previous = (float) config('bkt.default_pl0');

        for ($i = 0; $i < 10; $i++) {
            $mastery = $bkt->updateAfterCorrectResponse($student, $competency);

            $this->assertGreaterThanOrEqual($previous, $mastery->current_mastery);
            $this->assertLessThanOrEqual(1.0, $mastery->current_mastery);
            $this->assertGreaterThanOrEqual(0.0, $mastery->current_mastery);

            $previous = $mastery->current_mastery;
        }

        $this->assertSame(10, $mastery->observations_count);
        $this->assertSame(10, $mastery->correct_count);
    }

    public function test_degenerate_denominator_does_not_throw_or_produce_nan(): void
    {
        $bkt = new BayesianKnowledgeTracingService;
        $student = $this->makeStudent();
        $competency = $this->makeCompetency();

        // Force a zero denominator for an incorrect response:
        // numerator = priorL*pS = 0*anything = 0
        // denominator = 0 + (1-priorL)*(1-pG) = 1*(1-1) = 0
        StudentMastery::create([
            'student_id' => $student->id,
            'competency_id' => $competency->id,
            'initial_mastery' => 0.0,
            'current_mastery' => 0.0,
            'p_l0' => 0.0,
            'p_t' => 0.15,
            'p_g' => 1.0,
            'p_s' => 0.10,
        ]);

        $mastery = $bkt->updateAfterIncorrectResponse($student, $competency);

        $this->assertIsFloat($mastery->current_mastery);
        $this->assertFalse(is_nan($mastery->current_mastery));
        $this->assertGreaterThanOrEqual(0.0, $mastery->current_mastery);
        $this->assertLessThanOrEqual(1.0, $mastery->current_mastery);
        // Guard falls back to the prior L (0.0), then the transition term
        // still applies: updatedL = 0 + (1-0)*0.15 = 0.15.
        $this->assertEqualsWithDelta(0.15, $mastery->current_mastery, 1e-9);
    }

    public function test_replay_matches_step_by_step_updates(): void
    {
        $service = new BayesianKnowledgeTracingService();
        $student = $this->makeStudent();
        $competency = $this->makeCompetency();
        $sequence = [true, false, true, true, false, true];

        $stepwise = [];
        foreach ($sequence as $isCorrect) {
            $record = $isCorrect
                ? $service->updateAfterCorrectResponse($student, $competency)
                : $service->updateAfterIncorrectResponse($student, $competency);
            $stepwise[] = $record->current_mastery;
        }

        $replayed = $service->replay(
            (float) config('bkt.default_pl0'),
            [
                'p_g' => (float) config('bkt.default_pg'),
                'p_s' => (float) config('bkt.default_ps'),
                'p_t' => (float) config('bkt.default_pt'),
            ],
            $sequence,
        );

        $this->assertCount(count($sequence), $replayed);
        foreach ($stepwise as $i => $expected) {
            $this->assertEqualsWithDelta($expected, $replayed[$i], 1e-9);
        }
    }
}
