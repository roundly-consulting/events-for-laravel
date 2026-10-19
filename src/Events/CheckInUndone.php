<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * A check-in was undone.
 */
final readonly class CheckInUndone implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Rsvp $rsvp,
        public ?Model $actor,
    ) {}
}
