<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Internal;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\RsvpRules;
use RoundlyConsulting\Events\Support\SeatBook;

/**
 * A deleted attendee holds no seats: each of its RSVPs gives its seats back (counters exact, the
 * waitlist moves up) and is removed, event by event under each event's lock. Run when an
 * `AttendsEvents` model is force-deleted (or deleted without soft deletes).
 *
 * @internal
 */
final readonly class ReleaseAttendeeRsvpsAction
{
    public function __construct(
        private AutoPromoteAction $promote,
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
                    $book->remove($rsvp);
                    $this->promote->execute($book);
                }
            });
        }

        return $rsvps->count();
    }
}
