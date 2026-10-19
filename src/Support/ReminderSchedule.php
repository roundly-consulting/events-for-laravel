<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use RoundlyConsulting\Events\Models\Event;

/**
 * When a reminder is due. An offset's day part is calendar arithmetic in the event's own
 * timezone (`P1D` keeps the local wall clock across a DST change: 23 or 25 hours of real time);
 * its time part is absolute (`PT2H` is two hours). An all-day event has no meaningful start
 * time, so its reminders count back from `events.reminders.all_day_at` local time on its first
 * day (OQ-51).
 *
 * @internal
 */
final class ReminderSchedule
{
    /**
     * The event's reminder offsets: its own, else the configured ones.
     *
     * @return list<string>
     */
    public static function offsets(Event $event): array
    {
        return $event->reminders ?? EventsConfig::reminderOffsets();
    }

    public static function dueAt(Event $event, string $offset): ?CarbonImmutable
    {
        $interval = Durations::iso($offset);

        if ($interval === null) {
            return null;
        }

        $timezone = $event->timezone;

        if ($event->all_day) {
            [$hour, $minute] = EventsConfig::allDayReminderAt();
            $base = CarbonImmutable::parse((string) $event->starts_on, $timezone)->setTime($hour, $minute);
        } else {
            $base = $event->starts_at->setTimezone($timezone);
        }

        $days = CarbonInterval::create($interval->y, $interval->m, 0, $interval->d);
        $time = CarbonInterval::create(0, 0, 0, 0, $interval->h, $interval->i, $interval->s);

        // Calendar part on the local wall clock, then the absolute part in UTC.
        return $base->sub($days)->utc()->sub($time);
    }
}
