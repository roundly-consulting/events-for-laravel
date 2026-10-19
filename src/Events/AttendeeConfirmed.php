<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * An RSVP entered `going` (a free seat, a confirmed payment, a promotion). `late` marks a payment confirmed after its hold lapsed.
 */
final readonly class AttendeeConfirmed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Rsvp $rsvp,
        public bool $late = false,
    ) {}
}
