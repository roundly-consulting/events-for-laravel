<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * The status changes the package names. A lifecycle maps each to one of its transitions (`Contracts\EventLifecycle::transitionFor()`), or to null when it has none.
 */
#[TranslatedLabels('events::enums.event_verb')]
enum EventVerb: string
{
    use Helpers;

    case Publish = 'publish';
    case Unpublish = 'unpublish';
    case Postpone = 'postpone';
    case Reschedule = 'reschedule';
    case Cancel = 'cancel';
    case Complete = 'complete';
}
