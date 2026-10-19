<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Event;

/**
 * A deleted event was restored.
 */
final readonly class EventRestored implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Event $event,
        public ?Model $actor,
    ) {}
}
