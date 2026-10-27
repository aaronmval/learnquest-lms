<?php

namespace App\Notifications;

use App\Models\AnswerReviewRequest;
use App\Models\ClassPost;

class AnswerReviewDecided extends SystemAlert
{
    public function __construct(private AnswerReviewRequest $request, private ClassPost $post)
    {
    }

    private function approved(): bool
    {
        return $this->request->status === AnswerReviewRequest::STATUS_APPROVED;
    }

    protected function kind(): string
    {
        return 'answer_review_decided';
    }

    protected function preferenceKey(): string
    {
        return 'lessons';
    }

    protected function title(): string
    {
        return $this->approved() ? 'You can review your answers' : 'Answer review not approved';
    }

    protected function message(): string
    {
        return $this->approved()
            ? "Your teacher approved your request to review the answers to the \"{$this->post->title}\" quiz."
            : "Your teacher didn't approve reviewing the answers to the \"{$this->post->title}\" quiz yet.";
    }

    protected function url(): string
    {
        $base = "/pages/student/student-quiz.html?id={$this->post->class_id}&postId={$this->post->id}";

        return $this->approved()
            ? "{$base}&attempt={$this->request->quiz_attempt_id}&view=review"
            : "/pages/student/classwork.html?id={$this->post->class_id}&postId={$this->post->id}";
    }

    protected function icon(): string
    {
        return $this->approved() ? 'fa-unlock' : 'fa-lock';
    }
}
