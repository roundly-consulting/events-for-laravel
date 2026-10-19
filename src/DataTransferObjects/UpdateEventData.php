<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\NotGiven;

/**
 * An event change: only the fields given change (`NotGiven` is the default; `null` clears).
 *
 * A new start (`startsAt`, `allDayFrom`) is allowed on a draft only: a published event gets a
 * new date through `reschedule()`, which keeps the announced one and tells attendees. An end, a
 * doors time or a timezone change (instants kept) is an ordinary update.
 */
final readonly class UpdateEventData
{
    /**
     * @param  array<string, string>|string|NotGiven  $title
     * @param  array<string, string>|string|NotGiven|null  $description
     * @param  list<string>|NotGiven|null  $reminders
     * @param  array<string, mixed>|NotGiven  $meta
     */
    public function __construct(
        public array|string|NotGiven $title = NotGiven::Value,
        public array|string|NotGiven|null $description = NotGiven::Value,
        public CarbonInterface|string|NotGiven $startsAt = NotGiven::Value,
        public CarbonInterface|string|NotGiven $endsAt = NotGiven::Value,
        public CarbonInterval|string|NotGiven $duration = NotGiven::Value,
        public string|NotGiven $allDayFrom = NotGiven::Value,
        public string|NotGiven|null $allDayThrough = NotGiven::Value,
        public CarbonInterface|string|NotGiven|null $doorsOpenAt = NotGiven::Value,
        public string|NotGiven $timezone = NotGiven::Value,
        public Venue|int|NotGiven|null $venue = NotGiven::Value,
        public string|NotGiven|null $onlineUrl = NotGiven::Value,
        public AttendanceMode|NotGiven|null $attendanceMode = NotGiven::Value,
        public Visibility|NotGiven $visibility = NotGiven::Value,
        public int|NotGiven|null $capacity = NotGiven::Value,
        public int|NotGiven $maxGuests = NotGiven::Value,
        public bool|NotGiven $waitlist = NotGiven::Value,
        public bool|NotGiven $waitlistAutoPromote = NotGiven::Value,
        public CarbonInterface|string|NotGiven|null $rsvpOpensAt = NotGiven::Value,
        public CarbonInterface|string|NotGiven|null $rsvpClosesAt = NotGiven::Value,
        public GuestList|NotGiven $guestList = NotGiven::Value,
        public bool|NotGiven $showCounts = NotGiven::Value,
        public array|NotGiven|null $reminders = NotGiven::Value,
        public array|NotGiven $meta = NotGiven::Value,
    ) {}

    /**
     * Whether the change moves the start (or the all-day range).
     */
    public function movesStart(): bool
    {
        return ! $this->startsAt instanceof NotGiven || ! $this->allDayFrom instanceof NotGiven;
    }
}
