<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Lifecycles;

use RoundlyConsulting\Events\Contracts\EventState;
use RoundlyConsulting\Events\Enums\EventPhase;

/**
 * A host's own states: a community submission waits for approval before it is announced.
 */
enum CustomStatus: string implements EventState
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Called = 'called_off';
    case Done = 'done';

    public function phase(): EventPhase
    {
        return match ($this) {
            self::Draft, self::Submitted => EventPhase::Draft,
            self::Approved => EventPhase::Scheduled,
            self::Called => EventPhase::Cancelled,
            self::Done => EventPhase::Completed,
        };
    }
}
