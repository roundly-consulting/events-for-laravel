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

    /**
     * What the reminders count back from, and nothing is sent at or after: the start — for an
     * all-day event, `events.reminders.all_day_at` on its first day (after its local midnight
     * start, so `PT1H` is 08:00 on the day).
     */
    public static function anchor(Event $event): CarbonImmutable
    {
        if (! $event->all_day) {
            return $event->starts_at;
        }

        [$hour, $minute] = EventsConfig::allDayReminderAt();

        return CarbonImmutable::parse((string) $event->starts_on, $event->timezone)->setTime($hour, $minute)->utc();
    }

    public static function dueAt(Event $event, string $offset): ?CarbonImmutable
    {
        $interval = Durations::iso($offset);

        if ($interval === null) {
            return null;
        }

        $base = self::anchor($event)->setTimezone($event->timezone);

        $days = CarbonInterval::create($interval->y, $interval->m, 0, $interval->d);
        $time = CarbonInterval::create(0, 0, 0, 0, $interval->h, $interval->i, $interval->s);

        // Calendar part on the local wall clock, then the absolute part in UTC.
        return $base->sub($days)->utc()->sub($time);
    }
}
