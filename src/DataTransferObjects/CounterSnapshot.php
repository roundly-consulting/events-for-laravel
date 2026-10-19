<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

/**
 * An event's denormalised counters, stored or recounted from its RSVPs.
 *
 * @internal
 */
final readonly class CounterSnapshot
{
    /**
     * @param  array<int, int>  $tierSeats  ticket type id => seats taken
     */
    public function __construct(
        public int $seatsTaken,
        public int $goingCount,
        public int $interestedCount,
        public int $waitlistCount,
        public array $tierSeats = [],
    ) {}
}
