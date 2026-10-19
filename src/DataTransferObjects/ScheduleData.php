<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;

/**
 * When an event happens, as given: resolved once the timezone is known, so the order of the
 * builder's setters never changes the instant. A naked string is a wall-clock time in the
 * event's timezone; a Carbon, or a string with an offset or `Z`, is an instant.
 *
 * Timed: `startsAt` plus `endsAt` or `duration` (neither: `events.defaults.duration`).
 * All-day: `allDayFrom` (`Y-m-d`) and the inclusive `allDayThrough`.
 */
final readonly class ScheduleData
{
    public function __construct(
        public CarbonInterface|string|null $startsAt = null,
        public CarbonInterface|string|null $endsAt = null,
        public CarbonInterval|string|null $duration = null,
        public ?string $allDayFrom = null,
        public ?string $allDayThrough = null,
        public CarbonInterface|string|null $doorsOpenAt = null,
        public ?string $timezone = null,
    ) {}

    public function isAllDay(): bool
    {
        return $this->allDayFrom !== null;
    }
}
