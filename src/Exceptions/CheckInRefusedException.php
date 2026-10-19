<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

use RoundlyConsulting\Events\Support\Messages;

/**
 * A known code the door must turn away, with the reason: `wrong_event` (a code of another event;
 * which one is never named), `not_going`, `event_not_active` (cancelled, postponed or draft) or
 * `outside_window`.
 */
final class CheckInRefusedException extends EventsException
{
    public const string WRONG_EVENT = 'wrong_event';

    public const string NOT_GOING = 'not_going';

    public const string EVENT_NOT_ACTIVE = 'event_not_active';

    public const string OUTSIDE_WINDOW = 'outside_window';

    private string $reason = self::WRONG_EVENT;

    public static function because(string $reason): self
    {
        $exception = new self(Messages::get('check_in_refused.'.$reason), 'check_in_refused', 409);
        $exception->reason = $reason;

        return $exception;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
