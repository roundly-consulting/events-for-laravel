<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * The event takes no RSVP change now: its phase, its RSVP window, or its start.
 */
final class RsvpClosedException extends EventsException
{
    public static function closed(): self
    {
        return self::make('rsvp_closed', 409);
    }

    public static function notOpenYet(): self
    {
        return self::make('rsvp_closed', 409, [], 'rsvp_not_open_yet');
    }

    public static function started(): self
    {
        return self::make('rsvp_closed', 409, [], 'rsvp_started');
    }
}
