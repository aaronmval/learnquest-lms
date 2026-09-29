<?php

namespace App\Services\Quiz;

use App\Models\ClassPost;
use App\Models\QuizAttempt;
use App\Models\QuizSetting;
use App\Models\User;

/**
 * Teacher-configured quiz settings per lesson (Quiz & AI Setup page). A
 * lesson without saved settings inherits the teacher's most recently saved
 * settings, then the config defaults.
 */
class QuizSettingsService
{
    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    /**
     * @return array{question_count:int, time_limit_minutes:?int, difficulty_mix:array{easy:int, medium:int, hard:int}, max_attempts:?int, shuffle_questions:bool, shuffle_choices:bool, show_answers:bool, adaptive:bool, saved:bool}
     */
    public function for(ClassPost $post): array
    {
        $saved = $post->quizSetting;

        if ($saved) {
            return $this->toArray($saved) + ['saved' => true];
        }

        $professorId = $post->classRoom?->professor_id;
        $latest = $professorId ? QuizSetting::where('professor_id', $professorId)->latest('updated_at')->latest('id')->first() : null;

        return ($latest ? $this->toArray($latest) : $this->defaults()) + ['saved' => false];
    }

    public function save(ClassPost $post, User $professor, array $data): QuizSetting
    {
        return QuizSetting::updateOrCreate(
            ['class_post_id' => $post->id],
            [
                'professor_id' => $professor->id,
                'question_count' => (int) $data['question_count'],
                'time_limit_minutes' => $data['time_limit_minutes'] ?? null,
                'difficulty_mix' => $this->normalizeMix($data['difficulty_mix']),
                'max_attempts' => $data['max_attempts'] ?? null,
                'shuffle_questions' => (bool) $data['shuffle_questions'],
                'shuffle_choices' => (bool) $data['shuffle_choices'],
                'show_answers' => (bool) $data['show_answers'],
                'adaptive' => (bool) ($data['adaptive'] ?? true),
            ],
        );
    }

    /**
     * Exact number of questions per difficulty for $count questions, using
     * largest-remainder rounding so the counts always sum to $count.
     *
     * @param  array<string, int|float>  $mix  percents per difficulty
     * @return array{easy:int, medium:int, hard:int}
     */
    public function allocate(int $count, array $mix): array
    {
        $mix = $this->normalizeMix($mix);

        if (array_sum($mix) === 0) {
            $mix = ['easy' => 1, 'medium' => 1, 'hard' => 1];
        }

        $total = array_sum($mix);

        $exact = [];
        foreach (self::DIFFICULTIES as $difficulty) {
            $exact[$difficulty] = $count * $mix[$difficulty] / $total;
        }

        $allocation = array_map(fn ($v) => (int) floor($v), $exact);
        $remainders = [];
        foreach ($exact as $difficulty => $value) {
            $remainders[$difficulty] = $value - floor($value);
        }
        arsort($remainders);

        foreach (array_slice(array_keys($remainders), 0, $count - array_sum($allocation)) as $difficulty) {
            $allocation[$difficulty]++;
        }

        return [
            'easy' => $allocation['easy'],
            'medium' => $allocation['medium'],
            'hard' => $allocation['hard'],
        ];
    }

    /**
     * Submitted (non in-progress) attempts a student has used on a lesson,
     * across every version of its quiz — a regenerated quiz doesn't reset
     * the attempt limit.
     */
    public function attemptsUsed(ClassPost $post, User $student, ?int $exceptAttemptId = null): int
    {
        return QuizAttempt::query()
            ->whereHas('quiz', fn ($q) => $q->where('class_post_id', $post->id))
            ->where('student_id', $student->id)
            ->where('status', '!=', 'in_progress')
            ->when($exceptAttemptId, fn ($q) => $q->where('id', '!=', $exceptAttemptId))
            ->count();
    }

    private function defaults(): array
    {
        $defaults = config('quiz.default_settings');

        return [
            'question_count' => (int) config('quiz.default_question_count', 6),
            'time_limit_minutes' => $defaults['time_limit_minutes'],
            'difficulty_mix' => $this->normalizeMix($defaults['difficulty_mix']),
            'max_attempts' => $defaults['max_attempts'],
            'shuffle_questions' => (bool) $defaults['shuffle_questions'],
            'shuffle_choices' => (bool) $defaults['shuffle_choices'],
            'show_answers' => (bool) $defaults['show_answers'],
            'adaptive' => (bool) ($defaults['adaptive'] ?? true),
        ];
    }

    private function toArray(QuizSetting $setting): array
    {
        return [
            'question_count' => $setting->question_count,
            'time_limit_minutes' => $setting->time_limit_minutes,
            'difficulty_mix' => $this->normalizeMix($setting->difficulty_mix ?? []),
            'max_attempts' => $setting->max_attempts,
            'shuffle_questions' => $setting->shuffle_questions,
            'shuffle_choices' => $setting->shuffle_choices,
            'show_answers' => $setting->show_answers,
            'adaptive' => $setting->adaptive,
        ];
    }

    /**
     * @return array{easy:int, medium:int, hard:int}
     */
    private function normalizeMix(array $mix): array
    {
        return [
            'easy' => max(0, (int) round($mix['easy'] ?? 0)),
            'medium' => max(0, (int) round($mix['medium'] ?? 0)),
            'hard' => max(0, (int) round($mix['hard'] ?? 0)),
        ];
    }
}
