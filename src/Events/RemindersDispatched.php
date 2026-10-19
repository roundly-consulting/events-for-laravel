<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Events\Models\Event;

/**
 * The sweep sent one reminder offset of an event (once, whatever the number of sweepers).
 */
final readonly class RemindersDispatched implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Event $event,
        public string $offset,
        public int $recipients,
    ) {}
}
