<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * A waitlisted RSVP got seats (going, or held for payment).
 */
final readonly class AttendeePromoted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Rsvp $rsvp,
    ) {}
}
