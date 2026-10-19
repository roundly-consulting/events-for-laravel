<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * A ticket type that does not belong to the event, is trashed, or is hidden from the caller.
 */
final class TierNotFoundException extends EventsException
{
    public static function missing(): self
    {
        return self::make('tier_not_found', 404);
    }
}
