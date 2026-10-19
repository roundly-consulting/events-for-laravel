<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Notifications;

use Carbon\CarbonImmutable;

/**
 * A new date.
 */
class EventRescheduledNotification extends EventNotification
{
    public function type(): string
    {
        return 'rescheduled';
    }

    protected function lines(object $notifiable): array
    {
        $previous = $this->data['previous_starts_at'] ?? null;
        $reason = $this->data['reason'] ?? null;

        return array_values(array_filter([
            $this->line('body'),
            is_string($previous) ? $this->line('previous', ['previous' => $this->when(CarbonImmutable::parse($previous))]) : null,
            is_string($reason) && $reason !== '' ? (string) __('events::notifications.reason', ['reason' => $reason]) : null,
        ]));
    }

    protected function payload(): array
    {
        return ['previous_starts_at' => $this->data['previous_starts_at'] ?? null, 'reason' => $this->data['reason'] ?? null];
    }
}
