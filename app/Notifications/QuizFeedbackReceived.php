<?php

namespace App\Notifications;

use App\Models\ClassPost;
use App\Models\QuizFeedback;

class QuizFeedbackReceived extends SystemAlert
{
    public function __construct(private QuizFeedback $feedback, private ClassPost $post)
    {
    }

    protected function kind(): string
    {
        return 'quiz_feedback';
    }

    protected function preferenceKey(): string
    {
        return 'quiz_feedback';
    }

    protected function title(): string
    {
        return 'New quiz feedback';
    }

    protected function message(): string
    {
        $difficulty = str_replace('_', ' ', $this->feedback->difficulty);

        return "A student rated the \"{$this->post->title}\" quiz {$this->feedback->rating}/5 ({$difficulty}).";
    }

    protected function url(): string
    {
        return "/pages/professor/professor-class.html?id={$this->post->class_id}";
    }

    protected function icon(): string
    {
        return 'fa-comment-dots';
    }
}
