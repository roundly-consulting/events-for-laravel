<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Events;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\EventVerb;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventStates;
use RoundlyConsulting\Events\Support\EventTransitions;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;

/**
 * Postpones an event: it keeps its original date until rescheduled, and never completes on its own.
 *
 * The transition is the configured lifecycle's for `EventVerb::Postpone`.
 */
final readonly class PostponeEventAction
{
    public function execute(Event $event, ?Model $actor = null, bool $system = false, ?string $reason = null, bool $silent = false): TransitionResult
    {
        return EventTransitions::apply($event, EventStates::transitionFor(EventVerb::Postpone), $actor, $system, $reason, silent: $silent);
    }
}
