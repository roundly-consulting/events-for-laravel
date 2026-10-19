<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * The fixed classification every lifecycle state maps to. Package logic reads phases only, never concrete state values, so the lifecycle stays swappable.
 */
#[TranslatedLabels('events::enums.event_phase')]
enum EventPhase: string
{
    use Helpers;

    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Postponed = 'postponed';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    /**
     * Cancelled and Completed: nothing changes any more.
     */
    public function isTerminal(): bool
    {
        return $this === self::Cancelled || $this === self::Completed;
    }

    /**
     * Scheduled and Rescheduled: the event is on, RSVPs and check-in run.
     */
    public function isActive(): bool
    {
        return $this === self::Scheduled || $this === self::Rescheduled;
    }

    /**
     * Every phase but Draft: the event was announced.
     */
    public function isPublished(): bool
    {
        return $this !== self::Draft;
    }
}
