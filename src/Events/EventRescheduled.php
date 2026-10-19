<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Event;

/**
 * An event got a new date. `previousStartsAt` is the start it moved away from.
 */
final readonly class EventRescheduled implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Event $event,
        public CarbonImmutable $previousStartsAt,
        public ?string $reason,
        public ?Model $actor,
        public bool $silent = false,
    ) {}
}
