<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Notifications;

use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Notifications\EventCancelledNotification;
use RuntimeException;

/**
 * A host notice that fails: the write that caused it must still stand.
 */
final class ExplodingCancelledNotification extends EventCancelledNotification
{
    public function __construct(Event $event, ?Rsvp $rsvp = null, array $data = [])
    {
        throw new RuntimeException('The mail server is down.');
    }
}
