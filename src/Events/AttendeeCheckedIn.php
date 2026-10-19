<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Events\DataTransferObjects\CheckInResult;

/**
 * An attendee was checked in for the first time.
 */
final readonly class AttendeeCheckedIn implements ShouldDispatchAfterCommit
{
    public function __construct(
        public CheckInResult $result,
    ) {}
}
