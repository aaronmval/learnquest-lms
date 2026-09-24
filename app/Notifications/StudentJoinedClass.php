<?php

namespace App\Notifications;

use App\Models\ClassRoom;
use App\Models\User;

class StudentJoinedClass extends SystemAlert
{
    public function __construct(private ClassRoom $class, private User $student)
    {
    }

    protected function kind(): string
    {
        return 'student_joined';
    }

    protected function preferenceKey(): string
    {
        return 'enrollment';
    }

    protected function title(): string
    {
        return 'New student joined';
    }

    protected function message(): string
    {
        return "{$this->student->name} joined ".self::classLabel($this->class).'.';
    }

    protected function url(): string
    {
        return "/pages/professor/professor-class.html?id={$this->class->id}";
    }

    protected function icon(): string
    {
        return 'fa-user-plus';
    }
}
