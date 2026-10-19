<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Listeners;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Events\AttendeeConfirmed;
use RoundlyConsulting\Events\Events\AttendeePromoted;
use RoundlyConsulting\Events\Events\EventCancelled;
use RoundlyConsulting\Events\Events\EventMovedOnline;
use RoundlyConsulting\Events\Events\EventPostponed;
use RoundlyConsulting\Events\Events\EventRescheduled;
use RoundlyConsulting\Events\Events\EventUpdated;
use RoundlyConsulting\Events\Events\InvitationSent;
use RoundlyConsulting\Events\Notifications\Notifier;
use RoundlyConsulting\Events\Notifications\RecipientResolver;

/**
 * Turns the domain events (dispatched after the commit) into notices. Event-wide changes from a
 * `silently()` handle notify nobody; an edit only notifies when the time, the place or the join
 * link of an announced event changed.
 *
 * @internal
 */
final readonly class SendEventNotifications
{
    /** Attribute changes attendees are told about. */
    private const array NOTICEABLE = ['starts_at', 'ends_at', 'all_day', 'timezone', 'venue_id', 'online_url', 'attendance_mode'];

    public function __construct(
        private Notifier $notifier,
    ) {}

    public function invited(InvitationSent $sent): void
    {
        $invitee = $sent->invitation->invitee;

        if ($invitee instanceof Model) {
            $this->notifier->send('invited', $sent->invitation->event, [$invitee], data: ['token' => $sent->token, 'message' => $sent->message]);
        }
    }

    public function confirmed(AttendeeConfirmed $confirmed): void
    {
        $attendee = $confirmed->rsvp->attendee;

        if ($attendee instanceof Model) {
            $this->notifier->send('confirmed', $confirmed->rsvp->event, [$attendee], $confirmed->rsvp);
        }
    }

    public function promoted(AttendeePromoted $promoted): void
    {
        $attendee = $promoted->rsvp->attendee;

        if ($attendee instanceof Model) {
            $this->notifier->send('waitlist_promoted', $promoted->rsvp->event, [$attendee], $promoted->rsvp);
        }
    }

    public function updated(EventUpdated $updated): void
    {
        $changed = array_values(array_intersect($updated->changed, self::NOTICEABLE));

        if ($updated->silent || $changed === [] || $updated->event->phase === EventPhase::Draft) {
            return;
        }

        $this->notifier->send('updated', $updated->event, RecipientResolver::audience('updated', $updated->event, $updated->actor), data: ['changed' => $changed]);
    }

    public function postponed(EventPostponed $postponed): void
    {
        if (! $postponed->silent) {
            $this->notifier->send('postponed', $postponed->event, RecipientResolver::audience('postponed', $postponed->event, $postponed->actor), data: ['reason' => $postponed->reason]);
        }
    }

    public function rescheduled(EventRescheduled $rescheduled): void
    {
        if (! $rescheduled->silent) {
            $this->notifier->send('rescheduled', $rescheduled->event, RecipientResolver::audience('rescheduled', $rescheduled->event, $rescheduled->actor), data: [
                'previous_starts_at' => $rescheduled->previousStartsAt->toIso8601String(),
                'reason' => $rescheduled->reason,
            ]);
        }
    }

    public function cancelled(EventCancelled $cancelled): void
    {
        if (! $cancelled->silent) {
            $this->notifier->send('cancelled', $cancelled->event, RecipientResolver::audience('cancelled', $cancelled->event, $cancelled->actor), data: ['reason' => $cancelled->reason]);
        }
    }

    public function movedOnline(EventMovedOnline $moved): void
    {
        if (! $moved->silent) {
            $this->notifier->send('moved_online', $moved->event, RecipientResolver::audience('moved_online', $moved->event, $moved->actor));
        }
    }
}
