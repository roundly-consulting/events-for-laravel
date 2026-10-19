<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * An RSVP that does not belong to the event, or a payment reference nothing holds (unknown or superseded).
 */
final class RsvpNotFoundException extends EventsException
{
    public static function missing(): self
    {
        return self::make('rsvp_not_found', 404);
    }

    public static function unknownReference(): self
    {
        return self::make('rsvp_not_found', 404, [], 'rsvp_reference_not_found');
    }
}
