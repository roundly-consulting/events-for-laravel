<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * A venue that does not exist or is trashed.
 */
final class VenueNotFoundException extends EventsException
{
    public static function missing(): self
    {
        return self::make('venue_not_found', 404);
    }
}
