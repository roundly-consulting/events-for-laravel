<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Event;

/**
 * An event moved online (its status is unchanged).
 */
final readonly class EventMovedOnline implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Event $event,
        public ?Model $actor,
        public bool $silent = false,
    ) {}
}
