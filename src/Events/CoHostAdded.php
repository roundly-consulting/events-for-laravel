<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Event;

/**
 * A co-host was added to an event.
 */
final readonly class CoHostAdded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Event $event,
        public Model $host,
        public ?Model $actor,
    ) {}
}
