<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Rsvps;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Internal\ApplySeatChangesAction;
use RoundlyConsulting\Events\Actions\Internal\AutoPromoteAction;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\KeyTypes;
use RoundlyConsulting\Events\Support\RsvpRules;
use RoundlyConsulting\Events\Support\SeatBook;

/**
 * A manager takes someone off the list (checked-in or not): not going, recorded with who removed
 * them; the seats go to the waitlist.
 */
final readonly class RemoveAttendeeAction
{
    public function __construct(
        private ApplySeatChangesAction $seats,
        private AutoPromoteAction $promote,
    ) {}

    public function execute(Event $event, Rsvp|Model $who, ?Model $actor = null): Rsvp
    {
        KeyTypes::assertMorphable($actor);

        return EventTransaction::run($event, function () use ($event, $who, $actor): Rsvp {
            $book = SeatBook::open($event);
            $rsvp = RsvpRules::rsvpOf($event, $who) ?? throw RsvpNotFoundException::missing();

            if ($rsvp->status === RsvpStatus::NotGoing) {
                return $rsvp;
            }

            $rsvp->removed_by_type = $actor?->getMorphClass();
            $rsvp->removed_by_id = $actor?->getKey();
            $this->seats->execute($book, $rsvp, RsvpStatus::NotGoing, $rsvp->guests, $rsvp->tier_id === null ? null : (int) $rsvp->tier_id, $actor);
            $this->promote->execute($book);

            return $rsvp;
        });
    }
}
