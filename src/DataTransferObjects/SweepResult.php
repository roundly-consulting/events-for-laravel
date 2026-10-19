<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

/**
 * What a sweep did: lapsed holds released, waitlisted attendees promoted, events visited.
 */
final readonly class SweepResult
{
    public function __construct(
        public int $events = 0,
        public int $expiredHolds = 0,
        public int $promoted = 0,
        public int $reminders = 0,
        public int $notified = 0,
    ) {}
}
