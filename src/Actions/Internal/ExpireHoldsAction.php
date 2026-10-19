<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Internal;

use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\SeatBook;

/**
 * Releases the event's lapsed holds (`held_until` passed) — or, for a cancellation, every hold.
 * Run lazily under the event's lock before any seat decision, and by the sweep. Returns how many
 * were released.
 *
 * @internal
 */
final readonly class ExpireHoldsAction
{
    public function __construct(
        private ApplySeatChangesAction $seats,
    ) {}

    public function execute(SeatBook $book, bool $all = false): int
    {
        $holds = EventModels::rsvp()::query()
            ->where('event_id', $book->event->getKey())
            ->where('status', RsvpStatus::Held->value)
            ->when(! $all, static fn ($query) => $query->where('held_until', '<=', Clock::nowUtc()))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($holds as $hold) {
            $this->seats->execute($book, $hold, RsvpStatus::Expired, $hold->guests, $hold->tier_id === null ? null : (int) $hold->tier_id);
        }

        return $holds->count();
    }
}
