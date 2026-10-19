<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * An organizer the actor may not organize as.
 */
final class InvalidOrganizerException extends EventsException
{
    public static function notAllowed(): self
    {
        return self::make('invalid_organizer', 422, [], 'organizer_not_allowed');
    }
}
