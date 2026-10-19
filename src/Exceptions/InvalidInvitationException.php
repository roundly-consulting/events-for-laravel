<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * An unknown, revoked, expired or used invitation token, or one presented by someone else. Every case reads the same.
 */
final class InvalidInvitationException extends EventsException
{
    public static function invalid(): self
    {
        return self::make('invalid_invitation', 404);
    }
}
