<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * An RSVP joined the waitlist.
 */
final readonly class AttendeeWaitlisted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Rsvp $rsvp,
    ) {}
}
