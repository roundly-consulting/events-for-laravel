<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Notifications;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Support\OnlineUrls;

/**
 * Moved online — the join link for those who may see it.
 */
class EventMovedOnlineNotification extends EventNotification
{
    public function type(): string
    {
        return 'moved_online';
    }

    /**
     * The join link only for those who may see it (managers and going attendees).
     */
    protected function lines(object $notifiable): array
    {
        $url = OnlineUrls::for($this->event, $notifiable instanceof Model ? $notifiable : null);

        return array_values(array_filter([
            $this->line('body'),
            $url !== null ? (string) __('events::notifications.join', ['url' => $url]) : null,
        ]));
    }
}
