<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * A party size outside the ticket type's (or the event's) limits.
 */
final class InvalidGuestCountException extends EventsException
{
    public static function outOfRange(int $min, int $max): self
    {
        return self::make('invalid_guest_count', 422, ['min' => $min, 'max' => $max], 'guest_count_out_of_range');
    }
}
