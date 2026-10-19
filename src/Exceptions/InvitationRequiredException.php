<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * A private event admits invitees only.
 */
final class InvitationRequiredException extends EventsException
{
    public static function missing(): self
    {
        return self::make('invitation_required', 403);
    }
}
