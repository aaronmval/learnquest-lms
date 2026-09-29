<?php

namespace Tests\Unit;

use App\Services\Quiz\QuizSettingsService;
use App\Services\Quiz\QuizTrainingService;
use Tests\TestCase;

class QuizSettingsServiceTest extends TestCase
{
    public function test_allocate_uses_largest_remainder_and_always_sums_to_the_count(): void
    {
        $service = new QuizSettingsService;

        $this->assertSame(['easy' => 2, 'medium' => 2, 'hard' => 2], $service->allocate(6, ['easy' => 30, 'medium' => 40, 'hard' => 30]));
        $this->assertSame(['easy' => 2, 'medium' => 4, 'hard' => 4], $service->allocate(10, ['easy' => 20, 'medium' => 40, 'hard' => 40]));
        $this->assertSame(['easy' => 4, 'medium' => 2, 'hard' => 1], $service->allocate(7, ['easy' => 50, 'medium' => 35, 'hard' => 15]));
        $this->assertSame(['easy' => 0, 'medium' => 0, 'hard' => 5], $service->allocate(5, ['easy' => 0, 'medium' => 0, 'hard' => 100]));

        foreach ([5, 8, 11, 13, 15] as $count) {
            foreach ([[33, 33, 34], [10, 10, 80], [0, 0, 0], [45, 10, 45]] as [$e, $m, $h]) {
                $this->assertSame($count, array_sum($service->allocate($count, ['easy' => $e, 'medium' => $m, 'hard' => $h])));
            }
        }
    }

    public function test_suggested_difficulty_is_the_label_with_the_nearest_target(): void
    {
        config(['quiz.difficulty_targets' => ['easy' => 0.8, 'medium' => 0.6, 'hard' => 0.4]]);
        $training = new QuizTrainingService;

        $this->assertSame('easy', $training->suggestedDifficulty(0.95));
        $this->assertSame('medium', $training->suggestedDifficulty(0.62));
        $this->assertSame('hard', $training->suggestedDifficulty(0.1));
    }
}
