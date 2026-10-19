<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

/**
 * What a repair found (and, unless it was a dry run, fixed).
 */
final readonly class RepairReport
{
    public function __construct(
        public int $eventsChecked = 0,
        public int $countersFixed = 0,
        public int $orphansReleased = 0,
        public bool $dryRun = false,
    ) {}
}
