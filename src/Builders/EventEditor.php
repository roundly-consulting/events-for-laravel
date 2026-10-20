<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Builders;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Handles\EventHandle;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\NotGiven;

/**
 * `Events::for($event)->edit()` — the builder's setters for an existing event. `save()` builds an
 * {@see UpdateEventData} with only the touched fields and runs the handle's `update()`, so the
 * editor and the DTO run the same code. A new start on a published event goes through
 * `reschedule()`.
 */
final class EventEditor
{
    private UpdateEventData $data;

    public function __construct(
        private readonly EventHandle $handle,
    ) {
        $this->data = new UpdateEventData;
    }

    /**
     * @param  array<string, string>|string  $title
     */
    public function title(array|string $title): self
    {
        return $this->with('title', $title);
    }

    /**
     * @param  array<string, string>|string|null  $description
     */
    public function description(array|string|null $description): self
    {
        return $this->with('description', $description);
    }

    /**
     * A new start — drafts only.
     */
    public function startsAt(CarbonInterface|string $startsAt): self
    {
        return $this->with('startsAt', $startsAt);
    }

    public function endsAt(CarbonInterface|string $endsAt): self
    {
        return $this->with('endsAt', $endsAt);
    }

    public function duration(CarbonInterval|string $duration): self
    {
        return $this->with('duration', $duration);
    }

    /**
     * A new all-day range — drafts only.
     */
    public function allDay(string $from, ?string $through = null): self
    {
        return $this->with('allDayFrom', $from)->with('allDayThrough', $through);
    }

    public function doorsOpenAt(CarbonInterface|string|null $doorsOpenAt): self
    {
        return $this->with('doorsOpenAt', $doorsOpenAt);
    }

    /**
     * Another timezone: the instants stay, the local times and dates move.
     */
    public function timezone(string $timezone): self
    {
        return $this->with('timezone', $timezone);
    }

    public function at(Venue|int|null $venue): self
    {
        return $this->with('venue', $venue);
    }

    public function online(?string $url): self
    {
        return $this->with('onlineUrl', $url);
    }

    public function attendanceMode(?AttendanceMode $mode): self
    {
        return $this->with('attendanceMode', $mode);
    }

    public function visibility(Visibility $visibility): self
    {
        return $this->with('visibility', $visibility);
    }

    public function capacity(?int $capacity): self
    {
        return $this->with('capacity', $capacity);
    }

    public function maxGuests(int $guests): self
    {
        return $this->with('maxGuests', $guests);
    }

    /**
     * The waitlist on or off; automatic promotion changes only when given.
     */
    public function waitlist(bool $on = true, bool|NotGiven $autoPromote = NotGiven::Value): self
    {
        return $this->with('waitlist', $on)->with('waitlistAutoPromote', $autoPromote);
    }

    /**
     * The RSVP window: only the bounds given change (`null` clears one).
     */
    public function rsvpWindow(CarbonInterface|string|NotGiven|null $opensAt = NotGiven::Value, CarbonInterface|string|NotGiven|null $closesAt = NotGiven::Value): self
    {
        return $this->with('rsvpOpensAt', $opensAt)->with('rsvpClosesAt', $closesAt);
    }

    public function guestList(GuestList $guestList): self
    {
        return $this->with('guestList', $guestList);
    }

    public function showCounts(bool $show = true): self
    {
        return $this->with('showCounts', $show);
    }

    /**
     * @param  list<string>|null  $offsets
     */
    public function reminders(?array $offsets): self
    {
        return $this->with('reminders', $offsets);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): self
    {
        return $this->with('meta', $meta);
    }

    public function toData(): UpdateEventData
    {
        return $this->data;
    }

    public function save(): Event
    {
        return $this->handle->update($this->data)->event();
    }

    private function with(string $field, mixed $value): self
    {
        $this->data = new UpdateEventData(...[...get_object_vars($this->data), $field => $value]);

        return $this;
    }
}
