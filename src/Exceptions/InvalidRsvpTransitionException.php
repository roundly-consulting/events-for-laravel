<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * An RSVP change the state machine refuses.
 */
final class InvalidRsvpTransitionException extends EventsException
{
    public static function checkedIn(): self
    {
        return self::make('invalid_rsvp_transition', 409, [], 'rsvp_checked_in');
    }

    public static function paidTierChange(): self
    {
        return self::make('invalid_rsvp_transition', 409, [], 'rsvp_paid_tier_change');
    }

    public static function paidPartyChange(): self
    {
        return self::make('invalid_rsvp_transition', 409, [], 'rsvp_paid_party_change');
    }

    public static function checkedInParty(): self
    {
        return self::make('invalid_rsvp_transition', 409, [], 'rsvp_checked_in_party');
    }

    public static function notHeld(): self
    {
        return self::make('invalid_rsvp_transition', 409, [], 'rsvp_not_held');
    }

    public static function notGoing(): self
    {
        return self::make('invalid_rsvp_transition', 409, [], 'rsvp_not_going');
    }

    public static function notWaitlisted(): self
    {
        return self::make('invalid_rsvp_transition', 409, [], 'rsvp_not_waitlisted');
    }
}
