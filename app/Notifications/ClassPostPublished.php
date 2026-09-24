<?php

namespace App\Notifications;

use App\Models\ClassPost;

class ClassPostPublished extends SystemAlert
{
    public function __construct(private ClassPost $post)
    {
    }

    protected function kind(): string
    {
        return 'class_post';
    }

    protected function preferenceKey(): string
    {
        return $this->isLesson() ? 'lessons' : 'announcements';
    }

    protected function isLesson(): bool
    {
        return $this->post->type === 'lesson';
    }

    protected function title(): string
    {
        return $this->isLesson() ? 'New lesson posted' : 'New announcement';
    }

    protected function message(): string
    {
        $author = $this->post->author?->name ?? 'Your teacher';
        $what = $this->isLesson() ? 'a new lesson' : 'an announcement';
        $class = self::classLabel($this->post->classRoom);

        return "{$author} posted {$what} in {$class}: {$this->post->title}";
    }

    protected function url(): string
    {
        return $this->isLesson()
            ? "/pages/student/classwork.html?id={$this->post->class_id}&postId={$this->post->id}"
            : "/pages/student/enrolled-class.html?id={$this->post->class_id}";
    }

    protected function icon(): string
    {
        return $this->isLesson() ? 'fa-book-open' : 'fa-bullhorn';
    }
}
