<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Enums\Helpers;

/**
 * An RSVP's state. `going` and `held` (seats reserved, payment pending) take seats; `waitlisted` waits for them.
 */
#[TranslatedLabels('events::enums.rsvp_status')]
enum RsvpStatus: string implements HasColor
{
    use Helpers;

    case Going = 'going';
    case Held = 'held';
    case Waitlisted = 'waitlisted';
    case Interested = 'interested';
    case NotGoing = 'not_going';
    case Expired = 'expired';

    /**
     * Going and held RSVPs hold their party's seats.
     */
    public function takesSeats(): bool
    {
        return $this === self::Going || $this === self::Held;
    }

    public function color(): string
    {
        return match ($this) {
            self::Going => 'green',
            self::Held => 'amber',
            self::Waitlisted => 'orange',
            self::Interested => 'sky',
            self::NotGoing => 'gray',
            self::Expired => 'zinc',
        };
    }
}
