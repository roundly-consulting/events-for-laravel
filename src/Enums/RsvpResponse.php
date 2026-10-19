<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * What an attendee asks for. `going` yields going, held (a paid ticket type) or waitlisted.
 */
#[TranslatedLabels('events::enums.rsvp_response')]
enum RsvpResponse: string
{
    use Helpers;

    case Going = 'going';
    case Interested = 'interested';
    case NotGoing = 'not_going';
}
