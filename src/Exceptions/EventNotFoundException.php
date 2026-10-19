<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * A host lookup that found no live event (or one the viewer may not see).
 */
final class EventNotFoundException extends EventsException
{
    public static function missing(): self
    {
        return self::make('event_not_found', 404);
    }
}
