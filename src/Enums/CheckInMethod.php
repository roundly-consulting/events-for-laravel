<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * How an attendee was checked in: by scanning their code, or by hand.
 */
#[TranslatedLabels('events::enums.check_in_method')]
enum CheckInMethod: string
{
    use Helpers;

    case Code = 'code';
    case Manual = 'manual';
}
