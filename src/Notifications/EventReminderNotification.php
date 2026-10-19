<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Notifications;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Support\OnlineUrls;

/**
 * A reminder before the start.
 */
class EventReminderNotification extends EventNotification
{
    public function type(): string
    {
        return 'reminder';
    }

    protected function lines(object $notifiable): array
    {
        $url = OnlineUrls::for($this->event, $notifiable instanceof Model ? $notifiable : null);

        return array_values(array_filter([
            $this->line('body'),
            $url !== null ? (string) __('events::notifications.join', ['url' => $url]) : null,
        ]));
    }

    protected function payload(): array
    {
        return ['offset' => $this->data['offset'] ?? null];
    }
}
