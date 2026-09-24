<?php

namespace App\Notifications;

use App\Models\ClassPost;
use App\Models\User;

/**
 * Tells a professor a student's BKT mastery of a competency fell into Low —
 * a "student requiring intervention" signal.
 */
class StudentNeedsIntervention extends SystemAlert
{
    public function __construct(
        private User $student,
        private ClassPost $post,
        private string $competencyName,
        private float $mastery,
    ) {
    }

    protected function kind(): string
    {
        return 'intervention';
    }

    protected function preferenceKey(): string
    {
        return 'at_risk';
    }

    protected function title(): string
    {
        return 'Student may need help';
    }

    protected function message(): string
    {
        $percent = self::percent($this->mastery);

        return "{$this->student->name}'s mastery of {$this->competencyName} is Low ({$percent}%) after the \"{$this->post->title}\" quiz.";
    }

    protected function url(): string
    {
        return "/pages/professor/professor-class.html?id={$this->post->class_id}";
    }

    protected function icon(): string
    {
        return 'fa-user-graduate';
    }
}
