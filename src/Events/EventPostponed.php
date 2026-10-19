<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Event;

/**
 * An event was postponed; it keeps its original date until rescheduled.
 */
final readonly class EventPostponed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Event $event,
        public ?string $reason,
        public ?Model $actor,
        public bool $silent = false,
    ) {}
}
