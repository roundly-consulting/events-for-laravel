<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Events\Models\EventInvitation;

/**
 * An invitee declined through their token.
 */
final readonly class InvitationDeclined implements ShouldDispatchAfterCommit
{
    public function __construct(
        public EventInvitation $invitation,
    ) {}
}
