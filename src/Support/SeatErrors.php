<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use RoundlyConsulting\Events\Exceptions\EventFullException;
use RoundlyConsulting\Events\Exceptions\TierSoldOutException;

/**
 * "The event is full" versus "this ticket type is sold out".
 *
 * @internal
 */
final class SeatErrors
{
    public static function noRoom(SeatBook $book, int $party, int $currentParty = 0): EventFullException|TierSoldOutException
    {
        return $book->eventFitsReplacing($party, $currentParty) ? TierSoldOutException::soldOut() : EventFullException::full();
    }

    /**
     * The free seats belong to the waitlist: full for this party, of the event or of its ticket
     * type.
     */
    public static function waitlistFirst(?int $tierId): EventFullException|TierSoldOutException
    {
        return $tierId === null ? EventFullException::full() : TierSoldOutException::soldOut();
    }
}
