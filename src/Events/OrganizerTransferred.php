<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Event;

/**
 * An event got a new organizer.
 */
final readonly class OrganizerTransferred implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Event $event,
        public Model $from,
        public Model $to,
        public ?Model $actor,
    ) {}
}
