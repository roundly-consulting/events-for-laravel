<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Notifications;

use Illuminate\Support\Facades\App;
use RoundlyConsulting\Events\Contracts\EventUrls;

/**
 * An invitation to an event, with the accept link.
 */
class EventInvitationNotification extends EventNotification
{
    public function type(): string
    {
        return 'invited';
    }

    protected function lines(object $notifiable): array
    {
        $message = $this->data['message'] ?? null;

        return array_values(array_filter([
            $this->line('body'),
            is_string($message) && $message !== '' ? (string) __('events::notifications.invited.message', ['message' => $message]) : null,
        ]));
    }

    /**
     * The accept link carries the token; the database channel never stores it.
     */
    protected function action(object $notifiable): ?array
    {
        $token = $this->data['token'] ?? null;
        $url = is_string($token) ? App::make(EventUrls::class)->invitation($token) : null;

        return $url === null ? parent::action($notifiable) : [(string) __('events::notifications.invited.action'), $url];
    }

    protected function payload(): array
    {
        return ['message' => $this->data['message'] ?? null];
    }
}
