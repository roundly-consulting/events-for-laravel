<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * The ticket type has no seats left and the event keeps no waitlist.
 */
final class TierSoldOutException extends EventsException
{
    public static function soldOut(): self
    {
        return self::make('tier_sold_out', 409);
    }
}
