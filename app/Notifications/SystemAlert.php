<?php

namespace App\Notifications;

use App\Models\ClassRoom;
use Illuminate\Notifications\Notification;

/**
 * Base for the navbar's "System Alerts" drawer. Stored in the database
 * channel only, and sent synchronously — not queued — so alerts appear even
 * when no queue worker is running.
 */
abstract class SystemAlert extends Notification
{
    abstract protected function kind(): string;

    /** Settings toggle that controls this alert (see User::ALERT_PREFERENCES). */
    abstract protected function preferenceKey(): string;

    abstract protected function title(): string;

    abstract protected function message(): string;

    /** Shell page path (/pages/...) the navbar opens when the alert is clicked. */
    abstract protected function url(): string;

    protected function icon(): string
    {
        return 'fa-bell';
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Honour the recipient's Settings → Notifications toggles.
        if (method_exists($notifiable, 'wantsAlert') && ! $notifiable->wantsAlert($this->preferenceKey())) {
            return [];
        }

        return ['database'];
    }

    /**
     * @return array{kind: string, title: string, message: string, url: string, icon: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind(),
            'title' => $this->title(),
            'message' => $this->message(),
            'url' => $this->url(),
            'icon' => $this->icon(),
        ];
    }

    protected static function classLabel(ClassRoom $class): string
    {
        $label = $class->subject ?: $class->name;

        return $class->section ? "{$label} – {$class->section}" : $label;
    }

    protected static function percent(float $mastery): int
    {
        return (int) round($mastery * 100);
    }
}
