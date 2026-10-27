<?php

namespace App\Notifications;

use App\Models\ClassPost;
use App\Models\User;

class AnswerReviewRequested extends SystemAlert
{
    public function __construct(private User $student, private ClassPost $post)
    {
    }

    protected function kind(): string
    {
        return 'answer_review_requested';
    }

    protected function preferenceKey(): string
    {
        return 'quiz_feedback';
    }

    protected function title(): string
    {
        return 'Answer review request';
    }

    protected function message(): string
    {
        return "{$this->student->name} is asking to review the answers to the \"{$this->post->title}\" quiz.";
    }

    protected function url(): string
    {
        return "/pages/professor/professor-worksheets.html?classId={$this->post->class_id}&postId={$this->post->id}";
    }

    protected function icon(): string
    {
        return 'fa-key';
    }
}
