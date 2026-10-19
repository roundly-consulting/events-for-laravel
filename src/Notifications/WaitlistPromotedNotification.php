<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Notifications;

/**
 * Moved up from the waitlist — with the payment link while the seat is held.
 */
class WaitlistPromotedNotification extends EventNotification
{
    public function type(): string
    {
        return 'waitlist_promoted';
    }

    protected function lines(object $notifiable): array
    {
        $held = $this->rsvp !== null && $this->rsvp->isHeld() && $this->rsvp->held_until !== null;

        return array_values(array_filter([
            $this->line('body'),
            $held ? $this->line('held', ['until' => $this->when($this->rsvp->held_until)]) : null,
        ]));
    }

    protected function action(object $notifiable): ?array
    {
        $payment = $this->rsvp !== null && $this->rsvp->isHeld() ? $this->rsvp->payment_url : null;

        return $payment === null ? parent::action($notifiable) : [(string) __('events::notifications.waitlist_promoted.pay'), $payment];
    }

    protected function payload(): array
    {
        return ['rsvp' => $this->rsvp === null ? null : ['id' => $this->rsvp->getKey(), 'status' => $this->rsvp->status->value]];
    }
}
