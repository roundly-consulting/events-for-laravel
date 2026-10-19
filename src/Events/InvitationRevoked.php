<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\EventInvitation;

/**
 * An invitation was revoked; its token no longer works.
 */
final readonly class InvitationRevoked implements ShouldDispatchAfterCommit
{
    public function __construct(
        public EventInvitation $invitation,
        public ?Model $actor,
    ) {}
}
