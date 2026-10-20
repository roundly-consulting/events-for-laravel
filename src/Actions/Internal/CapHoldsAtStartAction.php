<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Internal;

use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\SeatBook;

/**
 * A hold never outlives the start (a payment after it is a refund, never a late admission). When
 * the start moves earlier, every live hold ending after the new start is shortened to it. Runs
 * under the event's lock, after the new start is written; returns how many holds were shortened.
 *
 * @internal
 */
final readonly class CapHoldsAtStartAction
{
    public function execute(SeatBook $book): int
    {
        $startsAt = $book->event->starts_at;

        return EventModels::rsvp()::query()
            ->where('event_id', $book->event->getKey())
            ->where('status', RsvpStatus::Held->value)
            ->where('held_until', '>', Clock::format($startsAt))
            ->update(['held_until' => Clock::format($startsAt)]);
    }
}
