<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * An attendee left (`going` or `held` → not going or interested). `wasPaid` is true when the seats were paid for: the host refunds.
 */
final readonly class RsvpCancelled implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Rsvp $rsvp,
        public bool $wasPaid,
    ) {}
}
