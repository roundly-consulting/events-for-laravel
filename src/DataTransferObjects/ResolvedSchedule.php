<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use Carbon\CarbonImmutable;

/**
 * A schedule resolved to UTC instants in its timezone, every rule checked.
 */
final readonly class ResolvedSchedule
{
    public function __construct(
        public string $timezone,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public bool $allDay,
        public ?CarbonImmutable $doorsOpenAt = null,
    ) {}
}
