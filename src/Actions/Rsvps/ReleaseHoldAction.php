<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Rsvps;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Internal\ApplySeatChangesAction;
use RoundlyConsulting\Events\Actions\Internal\AutoPromoteAction;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Exceptions\InvalidRsvpTransitionException;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\RsvpRules;
use RoundlyConsulting\Events\Support\SeatBook;

/**
 * The payment failed: the hold expires now, its seats are free, and the waitlist moves up.
 */
final readonly class ReleaseHoldAction
{
    public function __construct(
        private ApplySeatChangesAction $seats,
        private AutoPromoteAction $promote,
    ) {}

    public function execute(Event $event, Rsvp|Model $who, ?Model $actor = null): Rsvp
    {
        return EventTransaction::run($event, function () use ($event, $who, $actor): Rsvp {
            $book = SeatBook::open($event);
            $rsvp = RsvpRules::rsvpOf($event, $who) ?? throw RsvpNotFoundException::missing();

            if ($rsvp->status !== RsvpStatus::Held) {
                throw InvalidRsvpTransitionException::notHeld();
            }

            $this->seats->execute($book, $rsvp, RsvpStatus::Expired, $rsvp->guests, $rsvp->tier_id === null ? null : (int) $rsvp->tier_id, $actor);
            $this->promote->execute($book);

            return $rsvp;
        });
    }
}
