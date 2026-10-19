<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * An invitation's state. Only a pending invitation's token can be used.
 */
#[TranslatedLabels('events::enums.invitation_status')]
enum InvitationStatus: string
{
    use Helpers;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Revoked = 'revoked';
}
