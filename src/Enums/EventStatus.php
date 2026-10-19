<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Enums\Helpers;
use RoundlyConsulting\Events\Contracts\EventState;
use RoundlyConsulting\Events\Lifecycle\EventLifecycle;

/**
 * The states of the shipped {@see EventLifecycle}, one per phase.
 */
#[TranslatedLabels('events::enums.event_status')]
enum EventStatus: string implements EventState, HasColor
{
    use Helpers;

    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Postponed = 'postponed';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function phase(): EventPhase
    {
        return match ($this) {
            self::Draft => EventPhase::Draft,
            self::Scheduled => EventPhase::Scheduled,
            self::Postponed => EventPhase::Postponed,
            self::Rescheduled => EventPhase::Rescheduled,
            self::Cancelled => EventPhase::Cancelled,
            self::Completed => EventPhase::Completed,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Scheduled => 'green',
            self::Postponed => 'amber',
            self::Rescheduled => 'blue',
            self::Cancelled => 'red',
            self::Completed => 'zinc',
        };
    }
}
