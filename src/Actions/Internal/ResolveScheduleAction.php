<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Internal;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Events\DataTransferObjects\ResolvedSchedule;
use RoundlyConsulting\Events\DataTransferObjects\ScheduleData;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
use RoundlyConsulting\Events\Support\Durations;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\Timezones;
use RoundlyConsulting\Events\Support\WallClock;

/**
 * Resolves a schedule in its timezone and checks every rule: an end after the start, at most
 * `events.schedule.max_duration` long (in local or elapsed time, whichever allows more), doors
 * within 12 hours before the start, no doors time on an all-day event. All-day events start at
 * the local start of their first day and end at the local start of the day after the last one
 * (exclusive), so they survive a midnight DST switch.
 *
 * @internal
 */
final readonly class ResolveScheduleAction
{
    public function execute(ScheduleData $schedule, string $timezone): ResolvedSchedule
    {
        if (! Timezones::isValid($timezone)) {
            throw InvalidEventScheduleException::invalidTimezone($timezone);
        }

        if ($schedule->isAllDay()) {
            return $this->allDay($schedule, $timezone);
        }

        if ($schedule->startsAt === null) {
            throw InvalidEventScheduleException::startRequired();
        }

        $starts = WallClock::toUtc($schedule->startsAt, $timezone);
        $ends = match (true) {
            $schedule->endsAt !== null => WallClock::toUtc($schedule->endsAt, $timezone),
            $schedule->duration !== null => $starts->add(Durations::lenient($schedule->duration)
                ?? throw InvalidEventScheduleException::invalidDuration(is_string($schedule->duration) ? $schedule->duration : (string) $schedule->duration->spec())),
            default => $starts->add(EventsConfig::defaultDuration()),
        };

        $doors = $schedule->doorsOpenAt === null ? null : WallClock::toUtc($schedule->doorsOpenAt, $timezone);

        return $this->checked(new ResolvedSchedule($timezone, $starts, $ends, false, $doors));
    }

    public function checked(ResolvedSchedule $schedule): ResolvedSchedule
    {
        if (! $schedule->endsAt->greaterThan($schedule->startsAt)) {
            throw InvalidEventScheduleException::endBeforeStart();
        }

        $max = EventsConfig::maxDuration();
        // On the event's own calendar or in elapsed time, whichever allows more: 31 days are 31
        // local days across a DST switch, and an event kept at its exact length still moves.
        $limit = $schedule->startsAt->setTimezone($schedule->timezone)->add($max)->max($schedule->startsAt->add($max));

        if ($limit->lessThan($schedule->endsAt)) {
            throw InvalidEventScheduleException::tooLong($max->spec());
        }

        if ($schedule->doorsOpenAt !== null) {
            if ($schedule->allDay) {
                throw InvalidEventScheduleException::doorsOnAllDay();
            }

            if ($schedule->doorsOpenAt->greaterThan($schedule->startsAt)
                || $schedule->doorsOpenAt->lessThan($schedule->startsAt->subHours(12))) {
                throw InvalidEventScheduleException::doorsOutOfRange();
            }
        }

        return $schedule;
    }

    private function allDay(ScheduleData $schedule, string $timezone): ResolvedSchedule
    {
        if ($schedule->doorsOpenAt !== null) {
            throw InvalidEventScheduleException::doorsOnAllDay();
        }

        $from = (string) $schedule->allDayFrom;
        $through = $schedule->allDayThrough ?? $from;

        $starts = WallClock::startOfDay($from, $timezone);
        $last = WallClock::startOfDay($through, $timezone);

        if ($last->lessThan($starts)) {
            throw InvalidEventScheduleException::invalidDayRange();
        }

        $dayAfter = CarbonImmutable::createFromFormat('!Y-m-d', trim($through), $timezone);
        $ends = WallClock::startOfDay(($dayAfter instanceof CarbonImmutable ? $dayAfter : $last->setTimezone($timezone))->addDay()->format('Y-m-d'), $timezone);

        return $this->checked(new ResolvedSchedule($timezone, $starts, $ends, true));
    }
}
