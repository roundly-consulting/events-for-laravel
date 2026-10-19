<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * An RSVP to an event with ticket types that named none, while no single ticket type could be picked.
 */
final class TierRequiredException extends EventsException
{
    public static function missing(): self
    {
        return self::make('tier_required', 422);
    }
}
