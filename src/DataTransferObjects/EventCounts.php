<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

/**
 * An event's head counts: people going (guests included), RSVPs interested, people waiting, and
 * seats left (null: no capacity).
 */
final readonly class EventCounts
{
    public function __construct(
        public int $going,
        public int $interested,
        public int $waitlisted,
        public ?int $seatsLeft,
    ) {}
}
