<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Internal;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\RsvpCancelled;
use RoundlyConsulting\Events\Events\RsvpChanged;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\RsvpRules;
use RoundlyConsulting\Events\Support\SeatBook;

/**
 * A deleted attendee holds no seats: each of its RSVPs gives its seats back (counters exact, the
 * waitlist moves up) and is removed, event by event under each event's lock. Run when an
 * `AttendsEvents` model is force-deleted (or deleted without soft deletes). Each removal is
 * announced like any other way out — `RsvpChanged`, and `RsvpCancelled` (with `wasPaid`, the
 * refund trigger) for one that held seats — carrying the RSVP whose row is gone by then.
 *
 * @internal
 */
final readonly class ReleaseAttendeeRsvpsAction
{
    public function __construct(
        private AutoPromoteAction $promote,
        private Dispatcher $events,
    ) {}

    public function execute(Model $attendee): int
    {
        $rsvps = EventModels::rsvp()::query()
            ->where('attendee_type', $attendee->getMorphClass())
            ->where('attendee_id', $attendee->getKey())
            ->with('event')
            ->get();

        foreach ($rsvps as $found) {
            $event = $found->event;

            EventTransaction::run($event, function () use ($event, $found): void {
                $book = SeatBook::open($event, allowTrashed: true);
                $rsvp = RsvpRules::rsvpOf($event, $found);

                if ($rsvp !== null) {
                    $from = $rsvp->status;
                    // As ApplySeatChangesAction tells it: a going seat with a stored payment was paid.
                    $wasPaid = $from === RsvpStatus::Going && $rsvp->payment_reference !== null && $rsvp->amount !== null;

                    $book->remove($rsvp);

                    if ($from !== RsvpStatus::NotGoing) {
                        $this->events->dispatch(new RsvpChanged($rsvp, $from, null));
                    }

                    if ($from->takesSeats()) {
                        $this->events->dispatch(new RsvpCancelled($rsvp, $wasPaid));
                    }

                    $this->promote->execute($book);
                }
            });
        }

        return $rsvps->count();
    }
}
