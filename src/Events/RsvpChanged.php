<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * Every RSVP status change, with the status it left (null for a new RSVP).
 */
final readonly class RsvpChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Rsvp $rsvp,
        public ?RsvpStatus $from,
        public ?Model $actor,
    ) {}
}
