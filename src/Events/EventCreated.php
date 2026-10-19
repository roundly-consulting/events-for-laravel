<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Event;

/**
 * An event was created (as a draft).
 */
final readonly class EventCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Event $event,
        public ?Model $actor,
    ) {}
}
