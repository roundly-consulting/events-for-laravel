<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * A hold lapsed (or was released) without a payment; its seats are free again.
 */
final readonly class RsvpHoldExpired implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Rsvp $rsvp,
    ) {}
}
