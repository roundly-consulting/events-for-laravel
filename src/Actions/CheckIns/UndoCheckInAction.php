<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\CheckIns;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Events\CheckInUndone;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\RsvpRules;

/**
 * Undoes a check-in (scanned by mistake): the attendee is going and not checked in again.
 */
final readonly class UndoCheckInAction
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, Rsvp|Model $who, ?Model $actor = null): Rsvp
    {
        $rsvp = RsvpRules::rsvpOf($event, $who) ?? throw RsvpNotFoundException::missing();

        if ($rsvp->checked_in_at === null) {
            return $rsvp;
        }

        $rsvp->forceFill([
            'checked_in_at' => null,
            'checked_in_guests' => null,
            'check_in_method' => null,
            'checked_in_by_type' => null,
            'checked_in_by_id' => null,
        ])->save();

        $this->events->dispatch(new CheckInUndone($rsvp, $actor));

        return $rsvp;
    }
}
