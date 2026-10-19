<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Rsvps;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Internal\ApplySeatChangesAction;
use RoundlyConsulting\Events\Actions\Internal\ExpireHoldsAction;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Exceptions\InvalidRsvpTransitionException;
use RoundlyConsulting\Events\Exceptions\RsvpClosedException;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\RsvpRules;
use RoundlyConsulting\Events\Support\SeatBook;
use RoundlyConsulting\Events\Support\SeatErrors;

/**
 * A manager confirms a held RSVP by hand — paid at the door, say: held → going, with the payment
 * reference when given. A lapsed hold takes a free seat again, or the event is full. Not bound to
 * the start (cash at the door comes after it); a cancelled event refuses (`RsvpClosedException`).
 */
final readonly class ConfirmRsvpAction
{
    public function __construct(
        private ApplySeatChangesAction $seats,
        private ExpireHoldsAction $holds,
    ) {}

    public function execute(Event $event, Rsvp|Model $who, ?string $reference = null, ?Model $actor = null): Rsvp
    {
        return EventTransaction::run($event, function () use ($event, $who, $reference, $actor): Rsvp {
            $book = SeatBook::open($event);

            if ($event->phase === EventPhase::Cancelled) {
                throw RsvpClosedException::closed();
            }

            $this->holds->execute($book);
            $rsvp = RsvpRules::rsvpOf($event, $who) ?? throw RsvpNotFoundException::missing();
            $tierId = $rsvp->tier_id === null ? null : (int) $rsvp->tier_id;

            if ($rsvp->status === RsvpStatus::Going) {
                return $rsvp;
            }

            if ($rsvp->status === RsvpStatus::Expired && ! $book->fits($rsvp->partySize(), $tierId)) {
                throw SeatErrors::noRoom($book, $rsvp->partySize());
            }

            if ($rsvp->status !== RsvpStatus::Held && $rsvp->status !== RsvpStatus::Expired) {
                throw InvalidRsvpTransitionException::notHeld();
            }

            if ($reference !== null) {
                $rsvp->payment_reference = $reference;
            }

            return $this->seats->execute($book, $rsvp, RsvpStatus::Going, $rsvp->guests, $tierId, $actor, late: $rsvp->status === RsvpStatus::Expired);
        });
    }
}
