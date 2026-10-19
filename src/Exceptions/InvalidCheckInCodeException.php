<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * An unknown, revoked or malformed check-in code (and, on the unscoped check-in, a code the actor may not use).
 */
final class InvalidCheckInCodeException extends EventsException
{
    public static function unknown(): self
    {
        return self::make('invalid_code', 404);
    }
}
