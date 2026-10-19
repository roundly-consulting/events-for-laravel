<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Notifications;

use Illuminate\Support\Facades\App;
use RoundlyConsulting\Events\Contracts\EventUrls;

/**
 * A seat is confirmed: the check-in code as text (never a QR image in mail) and the ticket link.
 */
class RsvpConfirmedNotification extends EventNotification
{
    public function type(): string
    {
        return 'confirmed';
    }

    protected function lines(object $notifiable): array
    {
        $code = $this->rsvp?->checkInCode();

        return array_values(array_filter([
            $this->line('body'),
            $this->rsvp !== null && $this->rsvp->guests > 0 ? trans_choice('events::notifications.confirmed.guests', $this->rsvp->guests, ['count' => $this->rsvp->guests]) : null,
            $code !== null ? (string) __('events::notifications.confirmed.code', ['code' => $code]) : null,
        ]));
    }

    protected function action(object $notifiable): ?array
    {
        $url = $this->rsvp === null ? null : App::make(EventUrls::class)->rsvp($this->rsvp);

        return $url === null ? parent::action($notifiable) : [(string) __('events::notifications.confirmed.action'), $url];
    }

    /**
     * Never the check-in code: a database notification outlives the mail.
     */
    protected function payload(): array
    {
        return ['rsvp' => $this->rsvp === null ? null : ['id' => $this->rsvp->getKey(), 'status' => $this->rsvp->status->value, 'guests' => $this->rsvp->guests]];
    }
}
