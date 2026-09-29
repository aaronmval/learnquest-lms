<?php

namespace Tests\Unit;

use App\Models\QuizQuestion;
use App\Services\Learning\AdaptiveQuizService;
use Tests\TestCase;

class AdaptiveQuizServiceTest extends TestCase
{
    private AdaptiveQuizService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['quiz.adaptive_shift' => 20]);
        $this->service = app(AdaptiveQuizService::class);
    }

    public function test_mix_shifts_toward_hard_for_high_and_easy_for_low_mastery(): void
    {
        $mix = ['easy' => 30, 'medium' => 40, 'hard' => 30];

        $this->assertSame(['easy' => 10, 'medium' => 40, 'hard' => 50], $this->service->mixFor($mix, 'high'));
        $this->assertSame(['easy' => 50, 'medium' => 40, 'hard' => 10], $this->service->mixFor($mix, 'low'));
        $this->assertSame($mix, $this->service->mixFor($mix, 'developing'));
        $this->assertSame($mix, $this->service->mixFor($mix, null));

        // Not enough easy to move: the rest comes from medium.
        $this->assertSame(['easy' => 0, 'medium' => 35, 'hard' => 65], $this->service->mixFor(['easy' => 5, 'medium' => 50, 'hard' => 45], 'high'));
        // Already all hard: nothing to move.
        $this->assertSame(['easy' => 0, 'medium' => 0, 'hard' => 100], $this->service->mixFor(['easy' => 0, 'medium' => 0, 'hard' => 100], 'high'));
    }

    public function test_pool_covers_every_level_only_when_adaptive(): void
    {
        $settings = ['question_count' => 6, 'difficulty_mix' => ['easy' => 30, 'medium' => 40, 'hard' => 30]];

        $this->assertSame(['easy' => 2, 'medium' => 2, 'hard' => 2], $this->service->poolAllocation($settings + ['adaptive' => false]));
        $this->assertSame(['easy' => 3, 'medium' => 2, 'hard' => 3], $this->service->poolAllocation($settings + ['adaptive' => true]));
    }

    public function test_select_prefers_the_mix_and_fills_gaps_from_neighbours(): void
    {
        $bank = collect();
        foreach (['easy' => 3, 'medium' => 3, 'hard' => 1] as $difficulty => $n) {
            for ($i = 0; $i < $n; $i++) {
                $question = new QuizQuestion(['difficulty' => $difficulty]);
                $question->id = $bank->count() + 1;
                $bank->push($question);
            }
        }

        // Wants 0/2/3 but only 1 hard exists → the 2 missing come from medium, then easy.
        $picked = $this->service->select($bank, 5, ['easy' => 0, 'medium' => 40, 'hard' => 60]);

        $this->assertCount(5, $picked);
        $this->assertSame(5, $picked->unique('id')->count());
        $this->assertSame(['medium' => 3, 'hard' => 1, 'easy' => 1], $picked->countBy('difficulty')->all());

        // Never more than the bank holds.
        $this->assertCount(7, $this->service->select($bank, 10, ['easy' => 30, 'medium' => 40, 'hard' => 30]));
    }
}
