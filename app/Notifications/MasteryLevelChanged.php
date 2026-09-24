<?php

namespace App\Notifications;

use App\Models\ClassPost;
use App\Services\Learning\AdaptiveLearningService;

/**
 * Tells a student their BKT mastery of a competency moved into Low or High.
 */
class MasteryLevelChanged extends SystemAlert
{
    public function __construct(
        private ClassPost $post,
        private string $competencyName,
        private float $mastery,
        private string $level,
    ) {
    }

    protected function kind(): string
    {
        return 'mastery_'.$this->level;
    }

    protected function preferenceKey(): string
    {
        return 'mastery';
    }

    private function isLow(): bool
    {
        return $this->level === AdaptiveLearningService::LEVEL_LOW;
    }

    protected function title(): string
    {
        return $this->isLow() ? 'Mastery needs attention' : 'Mastery milestone';
    }

    protected function message(): string
    {
        $percent = self::percent($this->mastery);

        return $this->isLow()
            ? "Your mastery of {$this->competencyName} is Low ({$percent}%). Review \"{$this->post->title}\" and try QuestAI Coach for help."
            : "You reached High mastery in {$this->competencyName} ({$percent}%). Great work!";
    }

    protected function url(): string
    {
        return $this->isLow()
            ? "/pages/student/classwork.html?id={$this->post->class_id}&postId={$this->post->id}"
            : "/pages/student/enrolled-class.html?id={$this->post->class_id}";
    }

    protected function icon(): string
    {
        return $this->isLow() ? 'fa-triangle-exclamation' : 'fa-trophy';
    }
}
