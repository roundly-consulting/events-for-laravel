<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * The configured lifecycle has no transition for the verb, or no transition of that name.
 */
final class UnknownEventTransitionException extends EventsException
{
    public static function forVerb(string $verb): self
    {
        return self::make('unknown_transition', 422, ['verb' => $verb], 'unknown_transition_verb');
    }

    public static function named(string $transition): self
    {
        return self::make('unknown_transition', 422, ['transition' => $transition], 'unknown_transition_named');
    }
}
