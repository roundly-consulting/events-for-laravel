<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

/**
 * What `Events::repair()` / `events:repair` fixes: the denormalised counters, and RSVPs whose
 * attendee row is gone (a mass delete fires no model events). `dryRun` reports without writing.
 */
final readonly class RepairOptions
{
    public function __construct(
        public bool $counts = true,
        public bool $orphans = true,
        public bool $dryRun = false,
        public int $chunk = 100,
    ) {}
}
