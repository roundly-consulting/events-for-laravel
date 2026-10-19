<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * An invitee accepted through their token; `rsvp` is their answer.
 */
final readonly class InvitationAccepted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public EventInvitation $invitation,
        public Rsvp $rsvp,
    ) {}
}
