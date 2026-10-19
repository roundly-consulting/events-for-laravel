<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * An API reference to a host model (`{type, id}` or `alias:key`) that names no configured alias,
 * a class name, a malformed key or a missing row. One answer for all of them, so the API reveals
 * nothing about other records.
 */
final class UnknownMorphAliasException extends EventsException
{
    public static function unknown(): self
    {
        return self::make('unknown_alias', 422);
    }
}
