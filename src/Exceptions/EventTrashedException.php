<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * A write to a soft-deleted event: trashed events are read-only until restored.
 */
final class EventTrashedException extends EventsException
{
    public static function trashed(): self
    {
        return self::make('event_trashed', 409);
    }
}
