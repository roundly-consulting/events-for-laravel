<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * A payment arrived for a hold that lapsed with no seat left, or for an RSVP the attendee declined: refund it.
 */
final class RsvpHoldExpiredException extends EventsException
{
    public static function expired(): self
    {
        return self::make('hold_expired', 409);
    }
}
