<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Invitations;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Internal\ApplySeatChangesAction;
use RoundlyConsulting\Events\Actions\Internal\AutoPromoteAction;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Events\InvitationRevoked;
use RoundlyConsulting\Events\Exceptions\InvalidInvitationException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\RsvpRules;
use RoundlyConsulting\Events\Support\SeatBook;

/**
 * Revokes an invitation: its token stops working, and on a private event an invitee who is not
 * going yet (interested, waitlisted, holding seats) loses their place. A going invitee stays.
 * Someone never invited is refused (never another event's invitation).
 */
final readonly class RevokeInvitationAction
{
    public function __construct(
        private ApplySeatChangesAction $seats,
        private AutoPromoteAction $promote,
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, Model $invitee, ?Model $actor = null): EventInvitation
    {
        return EventTransaction::run($event, function () use ($event, $invitee, $actor): EventInvitation {
            $book = SeatBook::open($event);

            $invitation = EventInvitation::query()
                ->where('event_id', $event->getKey())
                ->where('invitee_type', $invitee->getMorphClass())
                ->where('invitee_id', $invitee->getKey())
                ->lockForUpdate()
                ->first() ?? throw InvalidInvitationException::invalid();

            $invitation->status = InvitationStatus::Revoked;
            $invitation->token_hash = null;
            $invitation->save();

            $rsvp = $event->visibility === Visibility::Private ? RsvpRules::rsvpOf($event, $invitee) : null;

            if ($rsvp !== null && in_array($rsvp->status, [RsvpStatus::Interested, RsvpStatus::Waitlisted, RsvpStatus::Held], true)) {
                $this->seats->execute($book, $rsvp, RsvpStatus::NotGoing, $rsvp->guests, $rsvp->tier_id === null ? null : (int) $rsvp->tier_id, $actor);
                $this->promote->execute($book);
            }

            $this->events->dispatch(new InvitationRevoked($invitation, $actor));

            return $invitation;
        });
    }
}
