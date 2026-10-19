<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Notifications;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Support\OnlineUrls;

/**
 * The time, the venue or the join link changed.
 */
class EventUpdatedNotification extends EventNotification
{
    public function type(): string
    {
        return 'updated';
    }

    protected function lines(object $notifiable): array
    {
        $url = OnlineUrls::for($this->event, $notifiable instanceof Model ? $notifiable : null);
        $changed = (array) ($this->data['changed'] ?? []);

        return array_values(array_filter([
            $this->line('body'),
            in_array('online_url', $changed, true) && $url !== null ? (string) __('events::notifications.join', ['url' => $url]) : null,
        ]));
    }

    protected function payload(): array
    {
        return ['changed' => $this->data['changed'] ?? []];
    }
}
