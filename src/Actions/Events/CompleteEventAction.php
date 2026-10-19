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
 * Completes an event early (it completes on its own at `ends_at`). Not before it started.
 *
 * The transition is the configured lifecycle's for `EventVerb::Complete`.
 */
final readonly class CompleteEventAction
{
    public function execute(Event $event, ?Model $actor = null, bool $system = false, bool $silent = false): TransitionResult
    {
        return EventTransitions::apply($event, EventStates::transitionFor(EventVerb::Complete), $actor, $system, null, silent: $silent);
    }
}
