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
 * Takes a scheduled event back to a draft — refused while people are going or hold seats (postpone or cancel it instead, so they are told).
 *
 * The transition is the configured lifecycle's for `EventVerb::Unpublish`.
 */
final readonly class UnpublishEventAction
{
    public function execute(Event $event, ?Model $actor = null, bool $system = false, bool $silent = false): TransitionResult
    {
        return EventTransitions::apply($event, EventStates::transitionFor(EventVerb::Unpublish), $actor, $system, null, silent: $silent);
    }
}
