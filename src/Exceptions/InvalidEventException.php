<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * An event write the rules refuse.
 */
final class InvalidEventException extends EventsException
{
    public static function titleRequired(): self
    {
        return self::make('invalid_event', 422, [], 'event_title_required');
    }

    public static function titleTooLong(int $max): self
    {
        return self::make('invalid_event', 422, ['max' => $max], 'event_title_too_long');
    }

    public static function descriptionTooLong(int $max): self
    {
        return self::make('invalid_event', 422, ['max' => $max], 'event_description_too_long');
    }

    public static function organizerRequired(): self
    {
        return self::make('invalid_event', 422, [], 'event_organizer_required');
    }

    public static function invalidOnlineUrl(): self
    {
        return self::make('invalid_event', 422, [], 'event_invalid_online_url');
    }

    public static function attendanceModeMismatch(string $mode): self
    {
        return self::make('invalid_event', 422, ['mode' => $mode], 'event_attendance_mode_mismatch');
    }

    public static function negative(string $field): self
    {
        return self::make('invalid_event', 422, ['field' => $field], 'event_negative');
    }

    public static function useReschedule(): self
    {
        return self::make('invalid_event', 422, [], 'event_use_reschedule');
    }

    public static function hasAttendees(): self
    {
        return self::make('invalid_event', 422, [], 'event_has_attendees');
    }

    public static function invalidReminders(): self
    {
        return self::make('invalid_event', 422, [], 'event_invalid_reminders');
    }

    public static function invalidDateTime(string $attribute): self
    {
        return self::make('invalid_event', 422, ['attribute' => $attribute], 'event_invalid_datetime');
    }

    public static function slugTaken(string $slug): self
    {
        return self::make('invalid_event', 422, ['slug' => $slug], 'event_slug_taken');
    }

    public static function finished(): self
    {
        return self::make('invalid_event', 422, [], 'event_finished');
    }

    public static function invalidSortDirection(string $direction): self
    {
        return self::make('invalid_event', 422, ['direction' => $direction], 'event_invalid_sort_direction');
    }

    public static function messageTooLong(int $max): self
    {
        return self::make('invalid_event', 422, ['max' => $max], 'invitation_message_too_long');
    }
}
