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
 * Cancels an event: held seats are released, auto-promotion stops, and attendees are told.
 *
 * The transition is the configured lifecycle's for `EventVerb::Cancel`.
 */
final readonly class CancelEventAction
{
    public function execute(Event $event, ?Model $actor = null, bool $system = false, ?string $reason = null, bool $silent = false): TransitionResult
    {
        return EventTransitions::apply($event, EventStates::transitionFor(EventVerb::Cancel), $actor, $system, $reason, silent: $silent);
    }
}
