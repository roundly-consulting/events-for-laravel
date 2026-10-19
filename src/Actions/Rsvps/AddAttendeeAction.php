<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Rsvps;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Internal\ApplySeatChangesAction;
use RoundlyConsulting\Events\Actions\Internal\AutoPromoteAction;
use RoundlyConsulting\Events\Actions\Internal\ExpireHoldsAction;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Exceptions\RsvpClosedException;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\KeyTypes;
use RoundlyConsulting\Events\Support\RsvpRules;
use RoundlyConsulting\Events\Support\SeatBook;
use RoundlyConsulting\Events\Support\SeatErrors;

/**
 * A manager puts someone on the list: going right away — a comp seat, no payment — on any ticket
 * type (hidden ones and closed sales included). Over capacity only with `overCapacity: true`.
 */
final readonly class AddAttendeeAction
{
    public function __construct(
        private ApplySeatChangesAction $seats,
        private ExpireHoldsAction $holds,
        private AutoPromoteAction $promote,
    ) {}

    public function execute(Event $event, Model $attendee, AdmissionTier|int|string|null $tier = null, int $guests = 0, bool $overCapacity = false, ?Model $actor = null): Rsvp
    {
        KeyTypes::assertMorphable($attendee);
        KeyTypes::assertMorphable($actor);

        return EventTransaction::run($event, function () use ($event, $attendee, $tier, $guests, $overCapacity, $actor): Rsvp {
            $book = SeatBook::open($event);

            if ($event->phase->isTerminal()) {
                throw RsvpClosedException::closed();
            }

            $this->holds->execute($book);
            $rsvp = RsvpRules::rsvpOf($event, $attendee);

            if ($rsvp === null) {
                $model = EventModels::rsvp();
                $rsvp = new $model;
                $rsvp->event_id = $event->getKey();
                $rsvp->attendee_type = $attendee->getMorphClass();
                $rsvp->attendee_id = $attendee->getKey();
            }

            $current = $rsvp->exists ? $rsvp->status : null;
            $currentParty = $rsvp->exists ? $rsvp->partySize() : 0;
            $currentTier = $rsvp->exists && $rsvp->tier_id !== null ? (int) $rsvp->tier_id : null;
            $chosen = RsvpRules::tier($book, $tier ?? $currentTier, manager: true);
            RsvpRules::assertParty($event, $chosen, $guests);
            $tierId = $chosen === null ? null : (int) $chosen->getKey();
            $party = 1 + $guests;
            $holds = $current !== null && $current->takesSeats();

            $fits = $holds ? $book->fitsReplacing($party, $tierId, $currentParty, $currentTier) : $book->fits($party, $tierId);

            if (! $fits && ! $overCapacity) {
                throw SeatErrors::noRoom($book, $party, $holds ? $currentParty : 0);
            }

            $rsvp->added_by_type = $actor?->getMorphClass();
            $rsvp->added_by_id = $actor?->getKey();
            $this->seats->execute($book, $rsvp, RsvpStatus::Going, $guests, $tierId, $actor);
            $this->promote->execute($book);

            return $rsvp;
        });
    }
}
