<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Events\Models\EventInvitation;
use SensitiveParameter;

/**
 * An invitation was sent (or re-sent with a new token). `token` is the bearer secret for the invitee's accept link — it is never stored; do not log it.
 */
final readonly class InvitationSent implements ShouldDispatchAfterCommit
{
    public function __construct(
        public EventInvitation $invitation,
        #[SensitiveParameter] public string $token,
        public ?string $message,
    ) {}
}
