<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Who sees an event's going attendees: managers only (`hidden`), the other going attendees (`attendees`) or every viewer (`public`).
 */
#[TranslatedLabels('events::enums.guest_list')]
enum GuestList: string
{
    use Helpers;

    case Hidden = 'hidden';
    case Attendees = 'attendees';
    case Public = 'public';
}
