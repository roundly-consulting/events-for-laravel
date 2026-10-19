<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Notifications;

/**
 * Postponed: the date is kept until a new one is set.
 */
class EventPostponedNotification extends EventNotification
{
    public function type(): string
    {
        return 'postponed';
    }

    protected function lines(object $notifiable): array
    {
        $reason = $this->data['reason'] ?? null;

        return array_values(array_filter([
            $this->line('body'),
            is_string($reason) && $reason !== '' ? (string) __('events::notifications.reason', ['reason' => $reason]) : null,
        ]));
    }

    protected function payload(): array
    {
        return ['reason' => $this->data['reason'] ?? null];
    }
}
