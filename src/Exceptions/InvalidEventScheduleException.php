<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * A start, end, duration, doors time or timezone the schedule rules refuse.
 */
final class InvalidEventScheduleException extends EventsException
{
    public static function startRequired(): self
    {
        return self::make('invalid_schedule', 422, [], 'schedule_start_required');
    }

    public static function endBeforeStart(): self
    {
        return self::make('invalid_schedule', 422, [], 'schedule_end_before_start');
    }

    public static function tooLong(string $max): self
    {
        return self::make('invalid_schedule', 422, ['max' => $max], 'schedule_too_long');
    }

    public static function doorsOutOfRange(): self
    {
        return self::make('invalid_schedule', 422, [], 'schedule_doors_out_of_range');
    }

    public static function doorsOnAllDay(): self
    {
        return self::make('invalid_schedule', 422, [], 'schedule_doors_on_all_day');
    }

    public static function invalidTimezone(string $timezone): self
    {
        return self::make('invalid_schedule', 422, ['timezone' => $timezone], 'schedule_invalid_timezone');
    }

    public static function invalidDateTime(string $value): self
    {
        return self::make('invalid_schedule', 422, ['value' => $value], 'schedule_invalid_datetime');
    }

    public static function invalidDuration(string $value): self
    {
        return self::make('invalid_schedule', 422, ['value' => $value], 'schedule_invalid_duration');
    }

    public static function nonexistentLocalTime(string $time, string $timezone): self
    {
        return self::make('invalid_schedule', 422, ['time' => $time, 'timezone' => $timezone], 'schedule_nonexistent_local_time');
    }

    public static function invalidDayRange(): self
    {
        return self::make('invalid_schedule', 422, [], 'schedule_invalid_day_range');
    }

    public static function notAllDay(): self
    {
        return self::make('invalid_schedule', 422, [], 'schedule_not_all_day');
    }

    /**
     * An announced event rescheduled to a date that is already over.
     */
    public static function inThePast(): self
    {
        return self::make('invalid_schedule', 422, [], 'schedule_in_the_past');
    }

    public static function invalidRsvpWindow(): self
    {
        return self::make('invalid_schedule', 422, [], 'schedule_invalid_rsvp_window');
    }
}
