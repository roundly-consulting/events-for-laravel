<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Event;

/**
 * An event was cancelled: holds were released and auto-promotion stopped. `releasedHolds` lists
 * the RSVPs whose holds the cancellation released (now `expired`).
 */
final readonly class EventCancelled implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<int|string>  $releasedHolds
     */
    public function __construct(
        public Event $event,
        public ?string $reason,
        public ?Model $actor,
        public bool $silent = false,
        public array $releasedHolds = [],
    ) {}
}
