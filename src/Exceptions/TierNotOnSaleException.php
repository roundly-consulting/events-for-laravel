<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * A ticket type outside its sale window.
 */
final class TierNotOnSaleException extends EventsException
{
    public static function closed(): self
    {
        return self::make('tier_not_on_sale', 409);
    }
}
