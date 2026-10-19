<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * No seats left and no waitlist (or a going party that cannot grow).
 */
final class EventFullException extends EventsException
{
    public static function full(): self
    {
        return self::make('event_full', 409);
    }
}
