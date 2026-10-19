<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * An API list query the package cannot answer: a malformed near-me filter, or a distance sort
 * without one.
 */
final class InvalidEventQueryException extends EventsException
{
    public static function invalidNear(): self
    {
        return self::make('invalid_query', 422, [], 'query_invalid_near');
    }

    public static function distanceNeedsNear(): self
    {
        return self::make('invalid_query', 400, [], 'distance_needs_near');
    }

    public static function invalidValue(string $filter): self
    {
        return self::make('invalid_query', 422, ['filter' => $filter], 'query_invalid_value');
    }
}
