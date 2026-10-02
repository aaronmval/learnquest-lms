<?php

namespace App\Notifications;

use App\Models\Module;
use App\Models\Subject;
use App\Models\User;

/**
 * A collaborator can't edit or delete a module that isn't theirs, so they
 * ask the subject's owner to do it.
 */
class ModuleChangeRequested extends SystemAlert
{
    public const ACTION_EDIT = 'edit';

    public const ACTION_DELETE = 'delete';

    public function __construct(
        private Subject $subject,
        private Module $module,
        private User $requester,
        private string $action,
        private string $note,
    ) {
    }

    protected function kind(): string
    {
        return 'module_change_requested';
    }

    protected function preferenceKey(): string
    {
        // Not a Settings toggle: a request from a co-teacher is always delivered.
        return 'module_requests';
    }

    protected function title(): string
    {
        return $this->action === self::ACTION_DELETE ? 'Delete requested' : 'Edit requested';
    }

    protected function message(): string
    {
        return "{$this->requester->name} asks you to {$this->action} \"{$this->module->title}\" in {$this->subject->name}: {$this->note}";
    }

    protected function url(): string
    {
        return "/pages/professor/professor-module-view.html?subject={$this->subject->id}";
    }

    protected function icon(): string
    {
        return $this->action === self::ACTION_DELETE ? 'fa-trash-can' : 'fa-pen-to-square';
    }
}
