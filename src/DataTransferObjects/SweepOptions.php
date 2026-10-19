<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

/**
 * What `Events::sweep()` (and `events:sweep`) handles per run: at most `limit` events, `chunk` at a
 * time, each under its own lock.
 */
final readonly class SweepOptions
{
    public function __construct(
        public ?int $limit = null,
        public int $chunk = 100,
    ) {}
}
