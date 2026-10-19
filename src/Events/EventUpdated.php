<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Event;

/**
 * An event changed. `changed` lists the attributes that did (`title`, `ends_at`, `venue_id`, …); `silent` is true for a `silently()` write (no notices).
 */
final readonly class EventUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $changed
     */
    public function __construct(
        public Event $event,
        public array $changed,
        public ?Model $actor,
        public bool $silent = false,
    ) {}
}
