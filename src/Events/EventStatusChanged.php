<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use BackedEnum;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Event;

/**
 * Any status change of an event (every lifecycle history row but the initial one). `from` / `to` are the configured lifecycle's states.
 */
final readonly class EventStatusChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Event $event,
        public ?string $transition,
        public BackedEnum|string|null $from,
        public BackedEnum|string $to,
        public ?Model $actor,
        public bool $silent = false,
    ) {}
}
